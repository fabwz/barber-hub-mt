<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Shared\Monitoring\Application\Contracts\HealthChecksReader;
use Src\Shared\Monitoring\Application\DTOs\HealthCheckEntry;
use Src\Shared\Monitoring\Application\DTOs\HealthTotals;
use stdClass;

final class DatabaseHealthChecksReader implements HealthChecksReader
{
    public function latest(): ?HealthCheckEntry
    {
        $row = DB::selectOne('SELECT last_checked_at, database_ok, queue_ok, checks FROM health_checks ORDER BY last_checked_at DESC LIMIT 1');

        return $row === null ? null : $this->entry($row);
    }

    /** @return list<HealthCheckEntry> */
    public function failuresSince(DateTimeImmutable $since, int $limit): array
    {
        $rows = DB::select(
            'SELECT last_checked_at, database_ok, queue_ok, checks FROM health_checks WHERE healthy = false AND bucket_minute >= ? ORDER BY last_checked_at DESC LIMIT ?',
            [$since->format('Y-m-d H:i:sP'), $limit],
        );

        return array_values(array_map($this->entry(...), $rows));
    }

    public function totalsSince(DateTimeImmutable $since): HealthTotals
    {
        $row = DB::selectOne(
            'SELECT coalesce(sum(checks), 0)::int AS total, coalesce(sum(checks) FILTER (WHERE healthy), 0)::int AS healthy FROM health_checks WHERE bucket_minute >= ?',
            [$since->format('Y-m-d H:i:sP')],
        );

        return new HealthTotals((int) ($row->total ?? 0), (int) ($row->healthy ?? 0));
    }

    private function entry(stdClass $row): HealthCheckEntry
    {
        return new HealthCheckEntry((string) $row->last_checked_at, (bool) $row->database_ok, (bool) $row->queue_ok, (int) $row->checks);
    }
}
