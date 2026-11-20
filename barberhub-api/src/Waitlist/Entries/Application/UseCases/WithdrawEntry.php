<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use DateTimeImmutable;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotLeavableException;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;

final readonly class WithdrawEntry
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * Take a row out of the line because its person booked the same service and day another way (LE-06): it is left, as when the person leaves it
     * themselves, and no longer counts. One that does not exist or already ended stays as it is. The caller holds the transaction.
     */
    public function execute(string $entryId, DateTimeImmutable $at): void
    {
        $entry = $this->entries->findForUpdate(new WaitlistEntryId($entryId));

        if ($entry === null) {
            return;
        }

        try {
            $entry->leave($at);
        } catch (EntryNotLeavableException) {
            return;
        }

        $this->entries->save($entry);
    }
}
