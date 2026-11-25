<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use DateTimeImmutable;
use LogicException;
use Src\Waitlist\Entries\Application\UseCases\FindLineEntry;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Application\DTOs\LineRow;

/**
 * The rows of the line as the offers read them, over the real use case that finds a row in an in-memory repository. Only reading is wired: the
 * expiry of an offer needs to know whose the row is, and nothing else here answers on rows.
 */
final readonly class OfferLineEntries implements LineEntries
{
    /** Receive the use case that finds a row. */
    public function __construct(private FindLineEntry $findEntry) {}

    public function find(string $entryId): ?LineRow
    {
        $entry = $this->findEntry->execute($entryId);

        return $entry === null ? null : new LineRow($entry->id, $entry->customerMembershipId, $entry->serviceId, $entry->desiredDate, $entry->offered);
    }

    public function activeOf(string $customerMembershipId): array
    {
        throw new LogicException('The expiry of an offer only reads one row.');
    }

    public function fulfil(string $entryId, DateTimeImmutable $at): bool
    {
        throw new LogicException('The expiry of an offer only reads one row.');
    }

    public function withdraw(string $entryId, DateTimeImmutable $at): void
    {
        throw new LogicException('The expiry of an offer only reads one row.');
    }
}
