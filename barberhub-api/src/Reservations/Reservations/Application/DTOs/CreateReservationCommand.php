<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

final readonly class CreateReservationCommand
{
    /**
     * Carry the person who books, the service, collaborator and start they choose, and whether they pay with a session of a voucher
     * (RF-28). The barbershop comes from the context, never from here. The waitlist, when it books the space of an offer, names the identifier
     * the reservation will have, so the offer can point at it before it exists, and the instant until which its payment may arrive (LE-04).
     */
    public function __construct(
        public string $userId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public bool $withVoucher = false,
        public ?string $reservationId = null,
        public ?DateTimeImmutable $payUntil = null,
    ) {}
}
