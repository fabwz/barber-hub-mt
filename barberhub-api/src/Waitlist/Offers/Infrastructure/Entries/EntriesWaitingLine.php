<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Entries;

use DateTimeImmutable;
use Src\Waitlist\Entries\Application\DTOs\WaitingEntry;
use Src\Waitlist\Entries\Application\UseCases\ListWaitingForSpace;
use Src\Waitlist\Entries\Application\UseCases\LockWaitingEntry;
use Src\Waitlist\Entries\Application\UseCases\MarkEntryOffered;
use Src\Waitlist\Entries\Application\UseCases\ReturnEntryToLine;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Application\DTOs\QueuedPerson;

final readonly class EntriesWaitingLine implements WaitingLine
{
    /** Receive the Entries use cases that list the line, hold a row, mark it as offered and put it back. */
    public function __construct(
        private ListWaitingForSpace $listWaiting,
        private LockWaitingEntry $lockEntry,
        private MarkEntryOffered $markOffered,
        private ReturnEntryToLine $returnToLine,
    ) {}

    /**
     * The rows that wait for the day with the collaborator or with any, through the Entries module, in the order they joined.
     *
     * @return list<QueuedPerson>
     */
    public function waitingFor(string $collaboratorId, string $date): array
    {
        return array_map(
            fn (WaitingEntry $entry): QueuedPerson => new QueuedPerson(
                $entry->id,
                $entry->customerMembershipId,
                $entry->serviceId,
                $entry->collaboratorId,
                $entry->desiredDate,
                $entry->joinedAt,
                $entry->customerEntryIds,
            ),
            $this->listWaiting->execute($date, $collaboratorId),
        );
    }

    /** Hold the row, with the lock of the line of its customer, through the Entries module. */
    public function hold(string $entryId, string $customerMembershipId): bool
    {
        return $this->lockEntry->execute($entryId, $customerMembershipId);
    }

    /** Mark the row as offered through the Entries module. */
    public function markOffered(string $entryId, DateTimeImmutable $at): void
    {
        $this->markOffered->execute($entryId, $at);
    }

    /** Put the row back to waiting through the Entries module. */
    public function returnToLine(string $entryId, DateTimeImmutable $at): void
    {
        $this->returnToLine->execute($entryId, $at);
    }
}
