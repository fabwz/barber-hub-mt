<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

final readonly class ReservationView
{
    /**
     * Carry a reservation with the names of its service, collaborator and, for the staff, customer, and the status it
     * shows at the instant of the read: a temporary reservation past its window shows as expired (DI-05). `expiresAt` is
     * the deadline of a reservation still waiting for its payment, and null otherwise.
     */
    public function __construct(
        public Reservation $reservation,
        public string $serviceName,
        public string $collaboratorName,
        public ?string $customerName,
        public ReservationStatus $status,
        public ?DateTimeImmutable $expiresAt,
    ) {}
}
