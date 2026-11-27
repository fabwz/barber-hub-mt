<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\DTOs;

use DateTimeImmutable;

/** What a reminder needs to know about the reservation at the moment it is due. */
final readonly class ReservationFacts
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
