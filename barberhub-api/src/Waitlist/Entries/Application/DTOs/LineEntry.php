<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\DTOs;

final readonly class LineEntry
{
    /** Carry a row of the line as the offers need it: whose it is, what for, which day, whether it has an offer in course and whether it is active. */
    public function __construct(
        public string $id,
        public string $customerMembershipId,
        public string $serviceId,
        public string $desiredDate,
        public bool $offered,
        public bool $active,
    ) {}
}
