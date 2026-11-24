<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Queue;

use DateTimeImmutable;
use Src\Notifications\Notices\Application\Contracts\ReminderJobs;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Src\Notifications\Notices\Infrastructure\Jobs\SendReminderJob;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class QueuedReminders implements ReminderJobs
{
    /** Receive the port that tells which barbershop the operation belongs to, to leave it in the task. */
    public function __construct(private BarbershopContext $context) {}

    /** Leave a task in the queue that only becomes available at the instant the reminder is due; a task that waits survives a restart of the workers. */
    public function schedule(string $reservationId, ReminderKind $kind, DateTimeImmutable $at): void
    {
        SendReminderJob::dispatch($reservationId, $this->context->current()->value, $kind->value, $at)->delay($at);
    }
}
