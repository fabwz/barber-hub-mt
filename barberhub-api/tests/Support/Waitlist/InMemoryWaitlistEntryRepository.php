<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;

/** The rows of the waitlist of the test, in memory, with the same questions the real repository answers. */
final class InMemoryWaitlistEntryRepository implements WaitlistEntryRepository
{
    /** @var array<string, WaitlistEntry> */
    private array $entries = [];

    private int $sequence = 0;

    private int $locks = 0;

    /** Generate a predictable identity. */
    public function nextId(): WaitlistEntryId
    {
        return new WaitlistEntryId(sprintf('0192f0c4-3333-7000-8000-%012d', ++$this->sequence));
    }

    /** Keep the row. */
    public function save(WaitlistEntry $entry): void
    {
        $this->entries[$entry->id->value] = $entry;
    }

    /** Count the locks taken on a line. */
    public function lockCustomer(CustomerMembershipId $customer): void
    {
        $this->locks++;
    }

    /** How many times a line was locked. */
    public function locks(): int
    {
        return $this->locks;
    }

    /** The active rows of the customer. */
    public function countActiveOfCustomer(CustomerMembershipId $customer): int
    {
        return count($this->activeOfCustomer($customer));
    }

    /** Whether an active row of the customer matches the combination. */
    public function hasActiveFor(CustomerMembershipId $customer, ServiceId $service, ?CollaboratorId $collaborator, DesiredDate $date): bool
    {
        return array_any($this->activeOfCustomer($customer), fn (WaitlistEntry $entry): bool => $entry->serviceId->value === $service->value
            && $entry->collaboratorId?->value === $collaborator?->value
            && $entry->desiredDate->value === $date->value);
    }

    /** The row, only if it belongs to the customer. */
    public function findOfCustomerForUpdate(WaitlistEntryId $id, CustomerMembershipId $customer): ?WaitlistEntry
    {
        $entry = $this->entries[$id->value] ?? null;

        return $entry !== null && $entry->customerMembershipId->value === $customer->value ? $entry : null;
    }

    /**
     * The active rows of the customer in the order they joined.
     *
     * @return list<WaitlistEntry>
     */
    public function activeOfCustomer(CustomerMembershipId $customer): array
    {
        $active = array_values(array_filter(
            $this->entries,
            fn (WaitlistEntry $entry): bool => $entry->customerMembershipId->value === $customer->value && $entry->isActive(),
        ));
        usort($active, fn (WaitlistEntry $a, WaitlistEntry $b): int => $a->joinedAt <=> $b->joinedAt);

        return $active;
    }

    /** Every row kept. */
    public function count(): int
    {
        return count($this->entries);
    }
}
