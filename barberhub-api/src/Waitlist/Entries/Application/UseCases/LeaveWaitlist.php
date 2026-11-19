<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Entries\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotLeavableException;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;

final readonly class LeaveWaitlist
{
    /** Receive the repository, the port that finds the customer role of a person and the transaction port. */
    public function __construct(
        private WaitlistEntryRepository $entries,
        private CustomerMemberships $customers,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Take a customer out of the line, at any moment (RF-30). The row is looked for among those of their own customer role, found
     * from the session and never from the request, so a row of another customer, of another barbershop or that does not exist
     * answers the same (CL-04, MT-03); a person without a role has no rows and gets the same answer. It works with the waitlist
     * switched off too: leaving is never blocked. The row stays, as `left`; it is locked first so a decision about it elsewhere waits.
     *
     * @throws EntryNotFoundException
     * @throws EntryNotLeavableException when the row already ended with a reservation
     */
    public function execute(string $userId, string $entryId, DateTimeImmutable $now): void
    {
        $membership = $this->customers->find($userId) ?? throw EntryNotFoundException::create();

        $this->transaction->run(function () use ($membership, $entryId, $now): void {
            $entry = $this->entries->findOfCustomerForUpdate(new WaitlistEntryId($entryId), new CustomerMembershipId($membership))
                ?? throw EntryNotFoundException::create();

            $entry->leave($now);
            $this->entries->save($entry);
        });
    }
}
