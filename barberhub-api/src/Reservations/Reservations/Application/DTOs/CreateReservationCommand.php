<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

final readonly class CreateReservationCommand
{
    /** Carry the person who books and the service, collaborator and start they choose. The barbershop comes from the context, never from here. */
    public function __construct(
        public string $userId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
    ) {}
}
