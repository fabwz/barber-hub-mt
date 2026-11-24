<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use DateTimeImmutable;
use Src\Notifications\Notices\Application\Contracts\ReminderJobs;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/** The tasks that were left for the reminders, in order, instead of a queue. */
final class RecordingReminderJobs implements ReminderJobs
{
    /** @var list<array{string, ReminderKind, DateTimeImmutable}> */
    public array $left = [];

    /** Keep the task. */
    public function schedule(string $reservationId, ReminderKind $kind, DateTimeImmutable $at): void
    {
        $this->left[] = [$reservationId, $kind, $at];
    }
}
