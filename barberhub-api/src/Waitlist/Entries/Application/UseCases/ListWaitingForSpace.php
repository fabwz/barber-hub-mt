<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use Src\Waitlist\Entries\Application\DTOs\WaitingEntry;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;

final readonly class ListWaitingForSpace
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * List the rows of the current barbershop that wait for a day with a collaborator or with any, in the order they joined, first to join first
     * (LE-02). Each one comes with every active row of its customer, so whoever offers a space can see that the person already holds an
     * offer on another row (LE-06). It reads the line of each customer once, however many rows they have in the list.
     *
     * @return list<WaitingEntry>
     */
    public function execute(string $date, string $collaboratorId): array
    {
        $waiting = $this->entries->waitingFor(DesiredDate::of($date), new CollaboratorId($collaboratorId));
        $activeOfCustomer = [];

        return array_map(function (WaitlistEntry $entry) use (&$activeOfCustomer): WaitingEntry {
            $activeOfCustomer[$entry->customerMembershipId->value] ??= array_map(
                fn (WaitlistEntry $active): string => $active->id->value,
                $this->entries->activeOfCustomer($entry->customerMembershipId),
            );

            return new WaitingEntry(
                $entry->id->value,
                $entry->customerMembershipId->value,
                $entry->serviceId->value,
                $entry->collaboratorId?->value,
                $entry->desiredDate->value,
                $entry->joinedAt,
                $activeOfCustomer[$entry->customerMembershipId->value],
            );
        }, $waiting);
    }
}
