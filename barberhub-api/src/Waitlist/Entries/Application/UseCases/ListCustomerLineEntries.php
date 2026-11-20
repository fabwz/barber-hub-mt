<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use Src\Waitlist\Entries\Application\DTOs\LineEntry;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;

final readonly class ListCustomerLineEntries
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * List the active rows (waiting or offered) of a customer role of the current barbershop, in the order they joined, for the offers: the ones
     * that have an offer to open (RF-32) and the ones that leave the line when the person books the same service and day (LE-06). A customer has
     * at most 3 active rows.
     *
     * @return list<LineEntry>
     */
    public function execute(string $customerMembershipId): array
    {
        return array_map(
            fn (WaitlistEntry $entry): LineEntry => FindLineEntry::view($entry),
            $this->entries->activeOfCustomer(new CustomerMembershipId($customerMembershipId)),
        );
    }
}
