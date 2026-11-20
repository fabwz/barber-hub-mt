<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\DTOs;

use DateTimeImmutable;

final readonly class QueuedPerson
{
    /**
     * Carry a row that waits in the line, as the Entries module gives it: who waits, what for and since when, and every active row of that
     * same person.
     *
     * @param  list<string>  $customerEntryIds  the active rows of the customer, this one included
     */
    public function __construct(
        public string $entryId,
        public string $customerMembershipId,
        public string $serviceId,
        public ?string $collaboratorId,
        public string $desiredDate,
        public DateTimeImmutable $joinedAt,
        public array $customerEntryIds,
    ) {}
}
