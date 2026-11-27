<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

final readonly class RegisterAssistedReservationCommand
{
    /**
     * Carry the owner who registers, the service, the collaborator and the start they chose, and the person the reservation is for: either an
     * assisted client already registered (`$assistedClientId`) or the data of a new one (`$name`, `$phone` and an optional `$email`). The
     * barbershop comes from the context, never from here.
     */
    public function __construct(
        public string $userId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public ?string $assistedClientId,
        public ?string $name = null,
        public ?string $phone = null,
        public ?string $email = null,
    ) {}
}
