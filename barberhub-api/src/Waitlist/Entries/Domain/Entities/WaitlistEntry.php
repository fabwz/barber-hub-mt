<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Entities;

use DateTimeImmutable;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotLeavableException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotOfferedException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotWaitingException;
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

    /**
     * The line offers the row a space (RF-31): it goes from waiting to offered and keeps its place, because the instant it joined is not
     * touched. Only a row that waits can receive an offer.
     *
     * @throws EntryNotWaitingException
     */
    public function offer(DateTimeImmutable $at): void
    {
        if ($this->status !== WaitlistStatus::Waiting) {
            throw EntryNotWaitingException::create();
        }

        $this->status = WaitlistStatus::Offered;
        $this->updatedAt = $at;
    }

    /**
     * The offer ended without a reservation, because its 15 minutes passed or the customer turned it down (LE-05): the row goes back to
     * waiting with the place it had, so it can receive another space later. A row in any other state stays as it is, so one that left
     * while it had the offer does not come back, and repeating it changes nothing.
     */
    public function returnToLine(DateTimeImmutable $at): void
    {
        if ($this->status !== WaitlistStatus::Offered) {
            return;
        }

        $this->status = WaitlistStatus::Waiting;
        $this->updatedAt = $at;
    }

    /**
     * The offer of the row ended with a reservation (RF-32): the row is fulfilled and leaves the line for good. Doing it twice changes nothing; a
     * row that is not offered, such as one the customer left while the offer was pending, has no offer to fulfil.
     *
     * @throws EntryNotOfferedException
     */
    public function fulfill(DateTimeImmutable $at): void
    {
        if ($this->status === WaitlistStatus::Fulfilled) {
            return;
        }

        if ($this->status !== WaitlistStatus::Offered) {
            throw EntryNotOfferedException::create();
        }

        $this->status = WaitlistStatus::Fulfilled;
        $this->updatedAt = $at;
    }

    /** Whether the row still counts against the limit of the customer and keeps its place in the line. */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
