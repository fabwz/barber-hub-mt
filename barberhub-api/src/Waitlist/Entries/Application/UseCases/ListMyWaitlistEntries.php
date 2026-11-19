<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use Src\Waitlist\Entries\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;

final readonly class ListMyWaitlistEntries
{
    /** Receive the repository and the port that finds the customer role of a person. */
    public function __construct(
        private WaitlistEntryRepository $entries,
        private CustomerMemberships $customers,
    ) {}

    /**
     * List the active rows (waiting or offered) a person has in the current barbershop, in the order they joined (RF-30, CL-04).
     * The scope is their own customer role, found from the session and never from the request; a person without one has no rows
     * yet, so the list is empty, not an error (RD-18). It creates nothing. A customer has at most 3 active rows, so it is not paged.
     *
     * @return list<WaitlistEntry>
     */
    public function execute(string $userId): array
    {
        $membership = $this->customers->find($userId);

        return $membership === null ? [] : $this->entries->activeOfCustomer(new CustomerMembershipId($membership));
    }
}
