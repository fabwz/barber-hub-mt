<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;

/** What another module needs to know about a reservation to write to its customer: its state now and its hours, services and person. */
final readonly class ReservationNotice
{
    public function __construct(
        public bool $confirmed,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ?string $customerMembershipId,
        public string $serviceId,
        public string $collaboratorId,
        public ?string $assistedClientId = null,
    ) {}
}
