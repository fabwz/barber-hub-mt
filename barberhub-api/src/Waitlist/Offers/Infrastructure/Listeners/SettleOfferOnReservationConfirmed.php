<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Listeners;

use DateTimeZone;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Shared\Domain\Contracts\Clock;
use Src\Waitlist\Offers\Application\UseCases\LeaveLinesOfBookedDay;
use Src\Waitlist\Offers\Application\UseCases\SettleAnsweredOffer;

/**
 * Reacts to a reservation that became confirmed, by its payment or because it needed none (RE-07, PA-06). It is not queued: it runs inside the
 * transaction that confirmed the reservation, with its barbershop already set, so the payment and the answer to the offer it was for are one fact and
 * nothing can confirm a reservation while the offer is lost. The two use cases it runs are idempotent and swallow what they cannot do, so a
 * reservation that no offer or line cares about is confirmed as before.
 */
final readonly class SettleOfferOnReservationConfirmed
{
    /** Receive the use case that accepts the offer the reservation was for, the one that leaves the lines of the day and the clock. */
    public function __construct(
        private SettleAnsweredOffer $settleOffer,
        private LeaveLinesOfBookedDay $leaveLines,
        private Clock $clock,
    ) {}

    /** Accept the offer of the reservation, if one waits for it, and take the customer out of the lines of that service and day (RF-32, LE-06). */
    public function handle(ReservationConfirmed $event): void
    {
        $now = $this->clock->now();
        $day = $event->startsAt->setTimezone(new DateTimeZone((string) config('app.timezone')))->format('Y-m-d');

        $this->settleOffer->execute($event->reservationId, $now);
        $this->leaveLines->execute($event->customerMembershipId, $event->serviceId, $day, $now);
    }
}
