<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Entries;

use DateTimeImmutable;
use Src\Waitlist\Entries\Application\DTOs\LineEntry;
use Src\Waitlist\Entries\Application\UseCases\FindLineEntry;
use Src\Waitlist\Entries\Application\UseCases\FulfillEntry;
use Src\Waitlist\Entries\Application\UseCases\ListCustomerLineEntries;
use Src\Waitlist\Entries\Application\UseCases\WithdrawEntry;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotOfferedException;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Application\DTOs\LineRow;

final readonly class EntriesLineEntries implements LineEntries
{
    /** Receive the Entries use cases that find, list, fulfil and withdraw the rows. */
    public function __construct(
        private FindLineEntry $findEntry,
        private ListCustomerLineEntries $listEntries,
        private FulfillEntry $fulfillEntry,
        private WithdrawEntry $withdrawEntry,
    ) {}

    /** Find the row through the Entries module. */
    public function find(string $entryId): ?LineRow
    {
        $entry = $this->findEntry->execute($entryId);

        return $entry === null ? null : self::row($entry);
    }

    /**
     * The active rows of the customer role through the Entries module.
     *
     * @return list<LineRow>
     */
    public function activeOf(string $customerMembershipId): array
    {
        return array_map(self::row(...), $this->listEntries->execute($customerMembershipId));
    }

    /** Fulfil the row through the Entries module; one the customer left meanwhile, or that is gone, is not fulfilled. */
    public function fulfil(string $entryId, DateTimeImmutable $at): bool
    {
        try {
            $this->fulfillEntry->execute($entryId, $at);

            return true;
        } catch (EntryNotOfferedException|EntryNotFoundException) {
            return false;
        }
    }

    /** Withdraw the row through the Entries module. */
    public function withdraw(string $entryId, DateTimeImmutable $at): void
    {
        $this->withdrawEntry->execute($entryId, $at);
    }

    private static function row(LineEntry $entry): LineRow
    {
        return new LineRow($entry->id, $entry->customerMembershipId, $entry->serviceId, $entry->desiredDate, $entry->offered);
    }
}
