<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;

final readonly class LockWaitingEntry
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * Hold a row of the line before it is offered a space: it takes the lock of the line of its customer, the same one joining takes (LE-01), and
     * then the lock of the row, so two spaces offered at the same time to one person, or a space offered while the person leaves, run one after
     * the other (LE-06). It tells whether the row still waits once it is held, which a row that someone else offered or the customer left in the
     * meantime does not. The caller holds the transaction.
     *
     * @return bool Whether the row of that customer is still waiting.
     */
    public function execute(string $entryId, string $customerMembershipId): bool
    {
        $customer = new CustomerMembershipId($customerMembershipId);
        $this->entries->lockCustomer($customer);

        $entry = $this->entries->findForUpdate(new WaitlistEntryId($entryId));

        return $entry !== null && $entry->customerMembershipId->equals($customer) && $entry->status === WaitlistStatus::Waiting;
    }
}
