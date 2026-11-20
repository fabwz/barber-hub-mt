<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use DateTimeImmutable;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Application\DTOs\QueuedPerson;

/**
 * The line of the test with one row that stops waiting between the moment it is read and the moment it is held, as it happens when another
 * process offers it or the customer leaves meanwhile: holding it answers that it is not waiting.
 */
final readonly class RefusingWaitingLine implements WaitingLine
{
    /** Wrap the real line and name the row that is gone when the offers try to hold it. */
    public function __construct(private WaitingLine $line, private string $refusedEntryId) {}

    /**
     * The line as it is.
     *
     * @return list<QueuedPerson>
     */
    public function waitingFor(string $collaboratorId, string $date): array
    {
        return $this->line->waitingFor($collaboratorId, $date);
    }

    /** Holding the refused row says it is not waiting; any other is held for real. */
    public function hold(string $entryId, string $customerMembershipId): bool
    {
        return $entryId !== $this->refusedEntryId && $this->line->hold($entryId, $customerMembershipId);
    }

    /** Mark the row as offered. */
    public function markOffered(string $entryId, DateTimeImmutable $at): void
    {
        $this->line->markOffered($entryId, $at);
    }

    /** Put the row back to waiting. */
    public function returnToLine(string $entryId, DateTimeImmutable $at): void
    {
        $this->line->returnToLine($entryId, $at);
    }
}
