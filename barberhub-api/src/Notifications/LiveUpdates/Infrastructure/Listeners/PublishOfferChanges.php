<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Src\Notifications\LiveUpdates\Application\Contracts\MembershipUsers;
use Src\Notifications\LiveUpdates\Infrastructure\Broadcasting\OfferChanged;
use Src\Waitlist\Offers\Domain\Events\OfferAnswered;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;
use Src\Waitlist\Offers\Domain\Events\OfferExpired;

/**
 * Turns each step of an offer of the waitlist into the pop-up of the person it was made to (RF-35, NO-08). It runs inside the transaction that made
 * the change and does no work of its own beyond finding the account behind the customer role: it hands the event to the dispatcher, which holds it
 * until the transaction commits and then leaves its publication in the `realtime` queue. A role with no account has nobody to tell.
 */
final readonly class PublishOfferChanges
{
    /** Receive the dispatcher and the port that finds the account of a customer role. */
    public function __construct(private Dispatcher $events, private MembershipUsers $users) {}

    /** A space was offered to a person: the pop-up opens with the moment their offer expires. */
    public function onCreated(OfferCreated $event): void
    {
        $user = $this->users->userOf($event->customerMembershipId, $event->barbershopId);

        if ($user !== null) {
            $this->events->dispatch(OfferChanged::created($user, $event->offerId, $event->barbershopId, $event->expiresAt, config()->string('app.timezone')));
        }
    }

    /** The 15 minutes of an offer ran out: the pop-up goes away. */
    public function onExpired(OfferExpired $event): void
    {
        $this->close($event->barbershopId, $event->customerMembershipId, $event->offerId, OfferChanged::EXPIRED);
    }

    /** The person turned the offer down or accepted it with a reservation: the pop-up goes away. */
    public function onAnswered(OfferAnswered $event): void
    {
        $this->close($event->barbershopId, $event->customerMembershipId, $event->offerId, $event->status === 'accepted' ? OfferChanged::ACCEPTED : OfferChanged::REJECTED);
    }

    /** Tell the person that their offer is over, and why. */
    private function close(string $barbershopId, string $membershipId, string $offerId, string $reason): void
    {
        $user = $this->users->userOf($membershipId, $barbershopId);

        if ($user !== null) {
            $this->events->dispatch(OfferChanged::closed($user, $offerId, $reason));
        }
    }
}
