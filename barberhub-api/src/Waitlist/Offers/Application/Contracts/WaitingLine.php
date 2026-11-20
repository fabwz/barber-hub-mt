<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

use DateTimeImmutable;
use Src\Waitlist\Offers\Application\DTOs\QueuedPerson;

interface WaitingLine
{
    /**
     * The rows of the current barbershop that wait for a day with the collaborator or with any, in the order they joined: first to join, first
     * to receive an offer (LE-02).
     *
     * @return list<QueuedPerson>
     */
    public function waitingFor(string $collaboratorId, string $date): array;

    /**
     * Hold a row before it is offered a space, with the lock of the line of its customer and the lock of the row (LE-06). It tells whether the
     * row still waits once it is held, which a row that was offered or that the customer left meanwhile does not.
     */
    public function hold(string $entryId, string $customerMembershipId): bool;

    /** Mark the row as offered, keeping its place in the line. */
    public function markOffered(string $entryId, DateTimeImmutable $at): void;

    /** Put the row back to waiting with its place, because its offer ended without a reservation (LE-05). One that left meanwhile stays out. */
    public function returnToLine(string $entryId, DateTimeImmutable $at): void;
}
