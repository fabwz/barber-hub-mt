<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Logging;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\CorrelationContext;
use Src\Shared\Domain\Contracts\OperationLog;

/**
 * Gives the work that has no request behind it its correlation identifier, and leaves a log entry for each attempt of a queued job (RNF-25).
 *
 * A console command or a scheduled task gets a new identifier that lasts for its whole execution. A job takes the identifier of whoever
 * queued it through its payload, so a retry keeps it; one queued with no identifier (before this existed) gets a new one per attempt.
 * The entries carry the name of the job and the exception class, never the payload or the message of the exception.
 */
final readonly class CorrelateBackgroundWork
{
    /** The payload key that carries the identifier of the code that queued the job. */
    public const string PAYLOAD_KEY = 'correlation_id';

    /** Receive the correlation port and the technical log. */
    public function __construct(private CorrelationContext $correlation, private OperationLog $log) {}

    /** Put the identifier in the payload of every job that is queued, and listen to the commands, the tasks and the jobs. */
    public function register(Dispatcher $events): void
    {
        Queue::createPayloadUsing(fn (): array => [self::PAYLOAD_KEY => $this->correlation->current()]);

        $events->listen(CommandStarting::class, $this->onCommand(...));
        $events->listen(ScheduledTaskStarting::class, fn (): string => $this->correlation->begin());
        $events->listen(JobProcessing::class, $this->onJobStarting(...));
        $events->listen(JobProcessed::class, fn (JobProcessed $event) => $this->log->record('queue.job.finished', $this->describe($event->job)));
        $events->listen(JobExceptionOccurred::class, fn (JobExceptionOccurred $event) => $this->log->record('queue.job.attempt_failed', [...$this->describe($event->job), 'exception' => $event->exception::class]));
        $events->listen(JobFailed::class, fn (JobFailed $event) => $this->log->record('queue.job.failed', [...$this->describe($event->job), 'exception' => $event->exception::class]));
    }

    /** A command that starts with no identifier (a request never runs one) gets its own. */
    private function onCommand(CommandStarting $event): void
    {
        if (! Context::has(LaravelCorrelationContext::KEY)) {
            $this->correlation->begin();
        }
    }

    /** Restore the identifier the job was queued with, or start one, and log the attempt. */
    private function onJobStarting(JobProcessing $event): void
    {
        $queuedWith = $event->job->payload()[self::PAYLOAD_KEY] ?? null;

        if (is_string($queuedWith) && Str::isUuid($queuedWith)) {
            $this->correlation->adopt($queuedWith);
        } else {
            $this->correlation->begin();
        }

        $this->log->record('queue.job.started', $this->describe($event->job));
    }

    /**
     * What identifies the job in the log.
     *
     * @return array<string, int|string>
     */
    private function describe(Job $job): array
    {
        return [
            'job' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'connection' => $job->getConnectionName(),
            'attempt' => $job->attempts(),
            'job_id' => $job->getJobId(),
        ];
    }
}
