<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use Src\Waitlist\Entries\Application\Contracts\CollaboratorNames;
use Src\Waitlist\Entries\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Entries\Application\DTOs\EntryView;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;

final readonly class ListMyWaitlistEntries
{
    /** Receive the repository and the ports that find the customer role of a person and the names of the collaborators. */
    public function __construct(
        private WaitlistEntryRepository $entries,
        private CustomerMemberships $customers,
        private CollaboratorNames $collaborators,
    ) {}

    /**
     * List the active rows (waiting or offered) a person has in the current barbershop, in the order they joined (RF-30, CL-04).
     * The scope is their own customer role, found from the session and never from the request; a person without one has no rows
     * yet, so the list is empty, not an error (RD-18). It creates nothing. A customer has at most 3 active rows, so it is not paged. Each row
     * carries the name of the collaborator it asked for, read once for the whole list.
     *
     * @return list<EntryView>
     */
    public function execute(string $userId): array
    {
        $membership = $this->customers->find($userId);

        if ($membership === null) {
            return [];
        }

        $rows = $this->entries->activeOfCustomer(new CustomerMembershipId($membership));

        // The names are read only when a row asked for a collaborator, and only once.
        $asked = array_filter($rows, fn (WaitlistEntry $entry): bool => $entry->collaboratorId !== null);
        $names = $asked === [] ? [] : $this->collaborators->names();

        return array_map(
            fn (WaitlistEntry $entry): EntryView => new EntryView($entry, $entry->collaboratorId === null ? null : ($names[$entry->collaboratorId->value] ?? null)),
            $rows,
        );
    }
}
