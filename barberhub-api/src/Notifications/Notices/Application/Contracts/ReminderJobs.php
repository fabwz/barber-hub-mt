<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use DateTimeImmutable;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/** Leaves a task in the queue that only becomes available when a reminder is due. */
interface ReminderJobs
{
    /** Leave the task for the instant; it waits for the commit of the transaction that is open, if any. */
    public function schedule(string $reservationId, ReminderKind $kind, DateTimeImmutable $at): void;
}
