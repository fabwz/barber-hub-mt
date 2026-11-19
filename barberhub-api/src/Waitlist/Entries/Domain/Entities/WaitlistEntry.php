<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Entities;

use DateTimeImmutable;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotLeavableException;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;

final class WaitlistEntry
{
    /** Rows a customer may have active (waiting or offered) in a barbershop at the same time (LE-01). */
    public const int MAX_ACTIVE_PER_CUSTOMER = 3;

    /** Hold the full state of a row. */
    private function __construct(
        public readonly WaitlistEntryId $id,
        public readonly CustomerMembershipId $customerMembershipId,
        public readonly ServiceId $serviceId,
        public readonly ?CollaboratorId $collaboratorId,
        public readonly DesiredDate $desiredDate,
        public private(set) WaitlistStatus $status,
        public readonly DateTimeImmutable $joinedAt,
        public readonly DateTimeImmutable $createdAt,
        public private(set) DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Put a customer in the line for a service on a day, with one collaborator or with any (null). It starts waiting, and the
     * instant it joins is what orders the line: first to join, first to receive an offer (LE-01, LE-02).
     */
    public static function join(
        WaitlistEntryId $id,
        CustomerMembershipId $customerMembershipId,
        ServiceId $serviceId,
        ?CollaboratorId $collaboratorId,
        DesiredDate $desiredDate,
        DateTimeImmutable $at,
    ): self {
        return new self($id, $customerMembershipId, $serviceId, $collaboratorId, $desiredDate, WaitlistStatus::Waiting, $at, $at, $at);
    }

    /** Rebuild a row from what is stored, without any check. */
    public static function reconstitute(
        WaitlistEntryId $id,
        CustomerMembershipId $customerMembershipId,
        ServiceId $serviceId,
        ?CollaboratorId $collaboratorId,
        DesiredDate $desiredDate,
        WaitlistStatus $status,
        DateTimeImmutable $joinedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $customerMembershipId, $serviceId, $collaboratorId, $desiredDate, $status, $joinedAt, $createdAt, $updatedAt);
    }

    /**
     * The customer leaves the line, at any moment, while it waits or has an offer (RF-30). Leaving twice changes nothing, so a
     * repeated press or a retry is harmless; a row that already ended with a reservation cannot be left.
     *
     * @throws EntryNotLeavableException
     */
    public function leave(DateTimeImmutable $at): void
    {
        if ($this->status === WaitlistStatus::Left) {
            return;
        }

        if (! $this->status->isActive()) {
            throw EntryNotLeavableException::create();
        }

        $this->status = WaitlistStatus::Left;
        $this->updatedAt = $at;
    }

    /** Whether the row still counts against the limit of the customer and keeps its place in the line. */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
