<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\DTOs;

final readonly class LineRow
{
    /** Carry a row of the line as the Entries module gives it for answering an offer: whose it is, what for, which day, and whether it has an offer in course. */
    public function __construct(
        public string $id,
        public string $customerMembershipId,
        public string $serviceId,
        public string $desiredDate,
        public bool $offered,
    ) {}
}
