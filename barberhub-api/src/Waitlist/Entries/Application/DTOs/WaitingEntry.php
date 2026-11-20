<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\DTOs;

use DateTimeImmutable;

final readonly class WaitingEntry
{
    /**
     * Carry a row that waits, with what the offers need to choose among them: who waits, for what, since when, and every active row of that
     * same person, which tells whether they already hold an offer for another row (LE-06).
     *
     * @param  list<string>  $customerEntryIds  the active rows of the customer, this one included
     */
    public function __construct(
        public string $id,
        public string $customerMembershipId,
        public string $serviceId,
        public ?string $collaboratorId,
        public string $desiredDate,
        public DateTimeImmutable $joinedAt,
        public array $customerEntryIds,
    ) {}
}
