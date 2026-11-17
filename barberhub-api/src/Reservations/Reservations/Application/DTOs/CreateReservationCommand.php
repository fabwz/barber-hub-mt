<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

final readonly class CreateReservationCommand
{
    /**
     * Carry the person who books, the service, collaborator and start they choose, and whether they pay with a session of a voucher
     * (RF-28). The barbershop comes from the context, never from here.
     */
    public function __construct(
        public string $userId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public bool $withVoucher = false,
    ) {}
}
