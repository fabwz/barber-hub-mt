<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** A job that logs when it runs and can fail on its first attempt, to follow the correlation identifier through the queue. */
final class CorrelationProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 0;

    public function __construct(public readonly bool $failsFirst = false) {}

    /** Log the attempt; fail the first one when asked. */
    public function handle(): void
    {
        Log::info('probe.handled', ['attempt' => $this->attempts()]);

        if ($this->failsFirst && $this->attempts() === 1) {
            throw new RuntimeException('The first attempt fails.');
        }
    }
}
