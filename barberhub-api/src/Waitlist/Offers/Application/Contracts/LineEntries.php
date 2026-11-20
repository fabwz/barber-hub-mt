<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

use DateTimeImmutable;
use Src\Waitlist\Offers\Application\DTOs\LineRow;

interface LineEntries
{
    /** Find a row of the current barbershop, whoever owns it, without locking it; none if it does not exist there. */
    public function find(string $entryId): ?LineRow;

    /**
     * The active rows (waiting or offered) of a customer role of the current barbershop, in the order they joined.
     *
     * @return list<LineRow>
     */
    public function activeOf(string $customerMembershipId): array;

    /**
     * Take the row out of the line because its offer ended with a reservation (RF-32). It tells whether the row was fulfilled: one that the customer
     * left while the offer was pending is not, and stays as it is.
     */
    public function fulfil(string $entryId, DateTimeImmutable $at): bool;

    /** Take the row out of the line because its person booked the same service and day another way (LE-06); one that already ended stays as it is. */
    public function withdraw(string $entryId, DateTimeImmutable $at): void;
}
