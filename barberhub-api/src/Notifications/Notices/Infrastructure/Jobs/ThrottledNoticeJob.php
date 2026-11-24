<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Jobs;

use DateTimeInterface;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * What every task that sends a notice shares: it keeps to the rate limit of the mail provider, and a failure is retried up to 3 times with
 * a growing wait before it stays in `failed_jobs` for the monitoring (RNF-09). Waiting for the rate limit is not a failure, so it does not use
 * an attempt: the task is limited by the number of exceptions and by time, not by the number of times it was taken from the queue.
 */
trait ThrottledNoticeJob
{
    use Queueable;

    /** Name of the rate limiter of the notices, defined by the service provider of the module. */
    public const string LIMITER = 'notices';

    /** Taken from the queue as many times as needed while the limiter holds it back; what ends it is `$maxExceptions` or `retryUntil()`. */
    public int $tries = 0;

    /** Failures before the task is recorded as failed (the 3 attempts of RNF-09). */
    public int $maxExceptions = 3;

    /** @var list<int> seconds to wait before the second and third attempts */
    public array $backoff = [10, 60];

    /** Give up after an hour, however many times the limiter held it back, so a task never waits forever. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * Keep to the rate limit of the provider: a task over it goes back to the queue and waits.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited(self::LIMITER)];
    }
}
