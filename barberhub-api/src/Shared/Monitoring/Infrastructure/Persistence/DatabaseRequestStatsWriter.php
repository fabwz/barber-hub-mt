<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Shared\Monitoring\Domain\Contracts\RequestStatsWriter;

final class DatabaseRequestStatsWriter implements RequestStatsWriter
{
    /** One upsert: the first request of a minute, route, status class and exception class creates the row, and the next ones add to it. */
    public function record(DateTimeImmutable $minute, string $method, string $routePattern, int $statusClass, string $exceptionClass, int $durationMs): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO http_stats (bucket_minute, method, route_pattern, status_class, exception_class, requests, total_ms, max_ms)
            VALUES (?, ?, ?, ?, ?, 1, ?, ?)
            ON CONFLICT (bucket_minute, method, route_pattern, status_class, exception_class) DO UPDATE SET
                requests = http_stats.requests + 1,
                total_ms = http_stats.total_ms + EXCLUDED.total_ms,
                max_ms = GREATEST(http_stats.max_ms, EXCLUDED.max_ms)
            SQL, [$minute->format('Y-m-d H:i:sP'), $method, $routePattern, $statusClass, $exceptionClass, $durationMs, $durationMs]);
    }
}
