<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Domain\Contracts;

use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;

interface WaitlistEntryRepository
{
    /** Generate the identity for a new row. */
    public function nextId(): WaitlistEntryId;

    /** Persist a new row, or the changes of an existing one, among those of the current barbershop. */
    public function save(WaitlistEntry $entry): void;

    /**
     * Hold the line of a customer of the current barbershop until the open transaction ends, so two requests of the same customer
     * count and write their rows one after the other and the limit of active rows cannot be beaten by a race (LE-01).
     */
    public function lockCustomer(CustomerMembershipId $customer): void;

    /** How many rows of a customer of the current barbershop are active (waiting or offered). */
    public function countActiveOfCustomer(CustomerMembershipId $customer): int;

    /** Whether the customer already has an active row for the same service, day and collaborator (or any, when null) in the current barbershop. */
    public function hasActiveFor(CustomerMembershipId $customer, ServiceId $service, ?CollaboratorId $collaborator, DesiredDate $date): bool;

    /** Find a row of a customer among those of the current barbershop and lock it until the transaction ends; none if it is not theirs (CL-04). */
    public function findOfCustomerForUpdate(WaitlistEntryId $id, CustomerMembershipId $customer): ?WaitlistEntry;

    /**
     * Find a row among those of the current barbershop, whoever owns it, and lock it until the transaction ends. The line uses it to offer a
     * space (RF-31): it is not scoped to a customer because the process that offers acts for the barbershop, not for a person.
     */
    public function findForUpdate(WaitlistEntryId $id): ?WaitlistEntry;

    /**
     * The rows of the current barbershop that wait for a day with the collaborator or with any, in the order they joined (LE-02): the
     * identifier breaks the tie between two of the same instant. A row that already has an offer is not waiting, so it is not listed.
     *
     * @return list<WaitlistEntry>
     */
    public function waitingFor(DesiredDate $date, CollaboratorId $collaborator): array;

    /**
     * The active rows of a customer in the current barbershop, in the order they joined.
     *
     * @return list<WaitlistEntry>
     */
    public function activeOfCustomer(CustomerMembershipId $customer): array;
}
