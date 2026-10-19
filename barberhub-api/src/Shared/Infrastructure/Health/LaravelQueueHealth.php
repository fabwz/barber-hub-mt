<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Health;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Src\Shared\Domain\Contracts\QueueHealth;
use Throwable;

final class LaravelQueueHealth implements QueueHealth
{
    /** Asking for the size reaches the backend of the configured driver (RD-11 leaves it pending). */
    public function isAvailable(): bool
    {
        try {
            Queue::connection()->size();

            return true;
        } catch (Throwable $e) {
            // Only the class: the message may carry the host or credentials.
            Log::warning('Health check: the queue is unavailable.', ['exception' => $e::class]);

            return false;
        }
    }
}
