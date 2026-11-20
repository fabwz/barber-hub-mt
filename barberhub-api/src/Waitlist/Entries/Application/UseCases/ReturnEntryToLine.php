<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use DateTimeImmutable;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;

final readonly class ReturnEntryToLine
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * Put a row back to waiting when its offer ended without a reservation (LE-05): it keeps the place it had and can receive another space. A
     * row that is not offered anymore, such as one the customer left meanwhile, or one that does not exist, stays as it is, so a retried task
     * changes nothing. The caller holds the transaction.
     */
    public function execute(string $entryId, DateTimeImmutable $at): void
    {
        $entry = $this->entries->findForUpdate(new WaitlistEntryId($entryId));

        if ($entry === null) {
            return;
        }

        $entry->returnToLine($at);
        $this->entries->save($entry);
    }
}
