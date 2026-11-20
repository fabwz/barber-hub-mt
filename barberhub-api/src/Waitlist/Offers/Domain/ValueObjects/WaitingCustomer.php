<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use DateTimeImmutable;

/** A person who waits in the line for a service on a day, with one collaborator or with any, seen from the offers (LE-01). */
final readonly class WaitingCustomer
{
    /**
     * Hold the row of the line and its customer, what they wait for and since when, and how many minutes their service needs, cleaning included.
     *
     * @param  list<WaitlistEntryId>  $customerEntryIds  every active row of the customer, this one included
     */
    public function __construct(
        public WaitlistEntryId $entryId,
        public CustomerMembershipId $customerId,
        public ServiceId $serviceId,
        public ?CollaboratorId $collaboratorId,
        public string $desiredDate,
        public DateTimeImmutable $joinedAt,
        public array $customerEntryIds,
        public int $minutesNeeded,
    ) {}

    /**
     * Whether the person can take the space (LE-03): they wait for the day it falls on, for the collaborator who freed it or for any, and
     * their service, cleaning included, fits in the whole space. That the collaborator offers their service is checked where the minutes
     * come from, because a service they do not offer has no minutes here.
     */
    public function isCompatibleWith(ReleasedSpace $space): bool
    {
        return $this->desiredDate === $space->date()
            && ($this->collaboratorId === null || $this->collaboratorId->equals($space->collaboratorId))
            && $space->fits($this->minutesNeeded);
    }
}
