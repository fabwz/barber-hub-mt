<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\UseCases;

use Src\Waitlist\Entries\Application\DTOs\LineEntry;
use Src\Waitlist\Entries\Domain\Contracts\WaitlistEntryRepository;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;

final readonly class FindLineEntry
{
    /** Receive the repository of the rows. */
    public function __construct(private WaitlistEntryRepository $entries) {}

    /**
     * Find a row of the current barbershop by its identifier, without locking it: the offers read whose it is before they answer on it (RF-32).
     * One that does not exist, or that is of another barbershop, is null.
     */
    public function execute(string $entryId): ?LineEntry
    {
        $entry = $this->entries->find(new WaitlistEntryId($entryId));

        return $entry === null ? null : self::view($entry);
    }

    /** Describe a row as the offers read it. */
    public static function view(WaitlistEntry $entry): LineEntry
    {
        return new LineEntry(
            $entry->id->value,
            $entry->customerMembershipId->value,
            $entry->serviceId->value,
            $entry->desiredDate->value,
            $entry->status === WaitlistStatus::Offered,
            $entry->isActive(),
        );
    }
}
