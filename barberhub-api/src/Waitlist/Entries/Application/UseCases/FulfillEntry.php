<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use DateTimeImmutable;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotOfferedException;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;

final readonly class FulfillEntry
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * Take a row out of the line because its offer ended with a reservation (RF-32): it is fulfilled and no longer counts against the limit of the
     * customer. Doing it twice changes nothing. The caller holds the transaction.
     *
     * @throws EntryNotFoundException
     * @throws EntryNotOfferedException when the row has no offer in course, such as one the customer left
     */
    public function execute(string $entryId, DateTimeImmutable $at): void
    {
        $entry = $this->entries->findForUpdate(new WaitlistEntryId($entryId)) ?? throw EntryNotFoundException::create();

        $entry->fulfill($at);
        $this->entries->save($entry);
    }
}
