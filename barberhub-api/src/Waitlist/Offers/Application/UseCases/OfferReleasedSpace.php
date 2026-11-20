<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\CustomerBookings;
use Src\Waitlist\Offers\Application\Contracts\OfferDeadlines;
use Src\Waitlist\Offers\Application\Contracts\ServiceMinutes;
use Src\Waitlist\Offers\Application\Contracts\SpaceAvailability;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Application\Contracts\WaitlistSwitch;
use Src\Waitlist\Offers\Application\DTOs\QueuedPerson;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Offers\Domain\ValueObjects\ReleasedSpace;
use Src\Waitlist\Offers\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitingCustomer;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

final readonly class OfferReleasedSpace
{
    /** Receive the repository of the offers, the ports to the line, the catalog, the availability, the reservations and the queue, the transaction port, the events of the domain and the barbershop of the operation. */
    public function __construct(
        private OfferRepository $offers,
        private WaitingLine $line,
        private ServiceMinutes $services,
        private CollaboratorLock $collaborators,
        private SpaceAvailability $availability,
        private CustomerBookings $bookings,
        private OfferDeadlines $deadlines,
        private WaitlistSwitch $waitlist,
        private TransactionRunner $transaction,
        private DomainEvents $events,
        private BarbershopContext $context,
    ) {}

    /**
     * Offer a freed space to the first compatible person of the line (RF-31). Nothing is offered when the waitlist is off (RF-09) or fewer than
     * 60 minutes remain before the space starts (LE-03); both are decided before anything is locked. In one transaction it then locks the
     * collaborator, the same lock the reservations take, and reads the line of the day in the order people joined (LE-02). Whoever is compatible
     * is tried in that order (day, collaborator or any, and a hole that fits their service with its cleaning, LE-03). A person is skipped, and the
     * next one tried, when the whole space is not free any more for their service (somebody booked it meanwhile, or it is held by another offer,
     * DI-05), when they are no longer waiting or they hold the lock of their line (a row offered or left in the meantime), when they already
     * had an offer for that space, whether they let it expire or turned it down (LE-05), when they hold another pending offer that overlaps it
     * (LE-06) and when they have a reservation of their own that overlaps it (LE-03). The first person who passes receives the offer: the offer
     * holds the whole space for 15 minutes (LE-04, DI-05), the row goes to offered, a task is left for the moment it expires and the fact is
     * announced for the notices. If nobody passes, nothing is held and the space stays public. Running it again for the same space finds it
     * held by the offer just made, so it offers nothing more: it is idempotent, and two releases at once run one after the other behind the lock.
     *
     * @return Offer|null The offer made, or null when the space was not offered.
     */
    public function execute(string $collaboratorId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt, DateTimeImmutable $now): ?Offer
    {
        $space = new ReleasedSpace(new CollaboratorId($collaboratorId), TimeInterval::between($startsAt, $endsAt));

        if (! $space->isOfferableAt($now) || ! $this->waitlist->isEnabled()) {
            return null;
        }

        return $this->transaction->run(function () use ($space, $now): ?Offer {
            if (! $this->collaborators->lock($space->collaboratorId->value)) {
                return null;
            }

            foreach ($this->compatibleLine($space) as $person) {
                $offer = $this->offerTo($person, $space, $now);

                if ($offer !== null) {
                    return $offer;
                }
            }

            return null;
        });
    }

    /**
     * The people of the line who are compatible with the space, in the order they joined. Whoever asks for a service the collaborator does
     * not offer, or that cannot be booked, has no minutes and is left out.
     *
     * @return list<WaitingCustomer>
     */
    private function compatibleLine(ReleasedSpace $space): array
    {
        $queued = $this->line->waitingFor($space->collaboratorId->value, $space->date());
        $minutes = $this->services->needed($space->collaboratorId->value, array_values(array_unique(array_map(fn (QueuedPerson $person): string => $person->serviceId, $queued))));
        $compatible = [];

        foreach ($queued as $person) {
            if (! isset($minutes[$person->serviceId])) {
                continue;
            }

            $waiting = new WaitingCustomer(
                new WaitlistEntryId($person->entryId),
                new CustomerMembershipId($person->customerMembershipId),
                new ServiceId($person->serviceId),
                $person->collaboratorId === null ? null : new CollaboratorId($person->collaboratorId),
                $person->desiredDate,
                $person->joinedAt,
                array_map(fn (string $id): WaitlistEntryId => new WaitlistEntryId($id), $person->customerEntryIds),
                $minutes[$person->serviceId],
            );

            if ($waiting->isCompatibleWith($space)) {
                $compatible[] = $waiting;
            }
        }

        return $compatible;
    }

    /** Make the offer to one person if every rule lets it pass, in the order that takes the locks before the checks that depend on them; null if any does not. */
    private function offerTo(WaitingCustomer $person, ReleasedSpace $space, DateTimeImmutable $now): ?Offer
    {
        $collaborator = $space->collaboratorId->value;

        if (! $this->availability->isFree($person->serviceId->value, $collaborator, $space->interval, $now)) {
            return null;
        }

        if (! $this->line->hold($person->entryId->value, $person->customerId->value)) {
            return null;
        }

        $theirs = TimeInterval::between($space->interval->start, $space->interval->start->modify("+{$person->minutesNeeded} minutes"));

        if ($this->offers->hadOfferOverlapping($person->entryId, $space->collaboratorId, $space->interval)
            || $this->offers->hasPendingOverlapping($person->customerEntryIds, $space->interval, $now)
            || $this->bookings->hasOverlapping($person->customerId->value, $theirs)) {
            return null;
        }

        $offer = Offer::create($this->offers->nextId(), $person->entryId, $space->collaboratorId, $space->interval, $now);
        $this->offers->save($offer);
        $this->line->markOffered($person->entryId->value, $now);
        $this->deadlines->expireAt($offer->id->value, $offer->expiresAt);
        $this->events->publish(new OfferCreated(
            $this->context->current()->value,
            $offer->id->value,
            $person->entryId->value,
            $person->customerId->value,
            $person->serviceId->value,
            $collaborator,
            $offer->interval->start,
            $offer->interval->end,
            $offer->expiresAt,
        ));

        return $offer;
    }
}
