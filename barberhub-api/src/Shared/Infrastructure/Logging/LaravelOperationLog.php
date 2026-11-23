<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Logging;

use Illuminate\Support\Facades\Log;
use Src\Shared\Domain\Contracts\OperationLog;

/** Writes the entry to the log of Laravel, whose context already holds the correlation identifier. */
final readonly class LaravelOperationLog implements OperationLog
{
    /** Write the entry at the info level. */
    public function record(string $event, array $context = []): void
    {
        Log::info($event, $context);
    }
}
