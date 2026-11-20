<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use DateTimeImmutable;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotWaitingException;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;

final readonly class MarkEntryOffered
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * Mark a row as offered once the line made it an offer (RF-31): it stops waiting, keeps its place and counts as active, so the person
     * cannot be offered another row of the same space while they decide. The caller holds the transaction and the lock of the row.
     *
     * @throws EntryNotFoundException
     * @throws EntryNotWaitingException
     */
    public function execute(string $entryId, DateTimeImmutable $at): void
    {
        $entry = $this->entries->findForUpdate(new WaitlistEntryId($entryId)) ?? throw EntryNotFoundException::create();

        $entry->offer($at);
        $this->entries->save($entry);
    }
}
