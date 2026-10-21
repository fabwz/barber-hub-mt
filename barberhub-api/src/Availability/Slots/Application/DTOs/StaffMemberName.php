<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\DTOs;

final readonly class StaffMemberName
{
    /** Carry the name a customer sees for a collaborator who can be booked. */
    public function __construct(
        public string $collaboratorId,
        public string $name,
    ) {}
}
