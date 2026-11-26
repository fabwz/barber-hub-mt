<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Shared\Monitoring\Application\Contracts\RequestStatsReader;
use Src\Shared\Monitoring\Application\DTOs\ErrorGroup;
use Src\Shared\Monitoring\Application\DTOs\RouteTiming;
use stdClass;

final class DatabaseRequestStatsReader implements RequestStatsReader
{
    private const int LIMIT = 50;

    /** @return list<ErrorGroup> */
    public function errorsSince(DateTimeImmutable $since): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT exception_class, method, route_pattern, status_class, sum(requests)::int AS requests
            FROM http_stats
            WHERE bucket_minute >= ? AND exception_class <> ''
            GROUP BY exception_class, method, route_pattern, status_class
            ORDER BY requests DESC, exception_class, route_pattern
            LIMIT ?
            SQL, [$since->format('Y-m-d H:i:sP'), self::LIMIT]);

        return array_values(array_map(
            fn (stdClass $row): ErrorGroup => new ErrorGroup((string) $row->exception_class, (string) $row->method, (string) $row->route_pattern, (int) $row->status_class, (int) $row->requests),
            $rows,
        ));
    }

    /** @return list<RouteTiming> */
    public function timingsSince(DateTimeImmutable $since): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT method, route_pattern,
                   sum(requests)::int AS requests,
                   (sum(total_ms) / sum(requests))::int AS average_ms,
                   max(max_ms) AS max_ms,
                   coalesce(sum(requests) FILTER (WHERE status_class = 5), 0)::int AS server_errors
            FROM http_stats
            WHERE bucket_minute >= ?
            GROUP BY method, route_pattern
            ORDER BY average_ms DESC, requests DESC, route_pattern
            LIMIT ?
            SQL, [$since->format('Y-m-d H:i:sP'), self::LIMIT]);

        return array_values(array_map(
            fn (stdClass $row): RouteTiming => new RouteTiming((string) $row->method, (string) $row->route_pattern, (int) $row->requests, (int) $row->average_ms, (int) $row->max_ms, (int) $row->server_errors),
            $rows,
        ));
    }
}
