<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Reservations;

use Src\Notifications\Notices\Application\Contracts\ReservationLookup;
use Src\Notifications\Notices\Application\DTOs\ReservationFacts;
use Src\Reservations\Reservations\Application\UseCases\GetReservationNotice;
use Src\Shared\Domain\Contracts\Clock;

final readonly class ReservationsReservationLookup implements ReservationLookup
{
    /** Receive the Reservations use case that reads a reservation without a person, and the clock that tells whether a temporary one is overdue. */
    public function __construct(
        private GetReservationNotice $getNotice,
        private Clock $clock,
    ) {}

    /** Read the reservation through the Reservations module and keep only what a reminder needs. */
    public function find(string $reservationId): ?ReservationFacts
    {
        $notice = $this->getNotice->execute($reservationId, $this->clock->now());

        if ($notice === null) {
            return null;
        }

        return new ReservationFacts($notice->confirmed, $notice->startsAt, $notice->endsAt, $notice->customerMembershipId, $notice->serviceId, $notice->collaboratorId);
    }
}
