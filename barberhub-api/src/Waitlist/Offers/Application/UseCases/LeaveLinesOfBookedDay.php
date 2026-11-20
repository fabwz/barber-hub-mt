<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Application\DTOs\LineRow;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAlreadyAnsweredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferExpiredException;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

final readonly class LeaveLinesOfBookedDay
{
    /** Receive the repository of the offers, the ports to the line and the lock of the collaborator, the offering of a freed space and the transaction port. */
    public function __construct(
        private OfferRepository $offers,
        private LineEntries $line,
        private CollaboratorLock $collaborators,
        private OfferReleasedSpace $offerSpace,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Take a customer out of the lines they wait in for the same service and day as a reservation that was just confirmed, whatever the collaborator
     * or the hour of the line (LE-06): booking another way answers what the line was for. It reads like leaving it themselves: the rows go to `left`
     * and stop counting. A row that already had an offer in course loses it too, as turned down, and that space goes on to the next compatible person
     * as when it is turned down (LE-05). The row fulfilled by the offer that this reservation answers is not active any more, so it is not touched,
     * and rows of other services or days stay. The caller holds the transaction.
     */
    public function execute(string $customerMembershipId, string $serviceId, string $date, DateTimeImmutable $now): void
    {
        $this->transaction->run(function () use ($customerMembershipId, $serviceId, $date, $now): void {
            $rows = array_filter(
                $this->line->activeOf($customerMembershipId),
                fn (LineRow $row): bool => $row->serviceId === $serviceId && $row->desiredDate === $date,
            );

            foreach ($rows as $row) {
                $lost = $row->offered ? $this->turnDownOffer($row, $now) : null;
                $this->line->withdraw($row->id, $now);

                if ($lost !== null) {
                    $this->offerSpace->execute($lost->collaboratorId->value, $lost->interval->start, $lost->interval->end, $now);
                }
            }
        });
    }

    /** Turn down the pending offer of the row, under the lock of its collaborator, and give it back; none if it had none or its time was up. */
    private function turnDownOffer(LineRow $row, DateTimeImmutable $now): ?Offer
    {
        $pending = $this->offers->pendingOfEntry(new WaitlistEntryId($row->id));

        if ($pending === null) {
            return null;
        }

        $this->collaborators->lock($pending->collaboratorId->value);
        $offer = $this->offers->findForUpdate($pending->id);

        if ($offer === null) {
            return null;
        }

        try {
            $offer->reject($now);
        } catch (OfferExpiredException|OfferAlreadyAnsweredException) {
            return null;
        }

        $this->offers->save($offer);

        return $offer;
    }
}
