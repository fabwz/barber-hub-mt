<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Src\Shared\Monitoring\Domain\Contracts\MonitoringRetention;

final class DatabaseMonitoringRetention implements MonitoringRetention
{
    /** The lock of the purge: whoever adds this key owns the hour. */
    public const string LOCK = 'monitoring:purge';

    private const int LOCK_SECONDS = 3600;

    /** Rows deleted from each table in one purge, so a purge never holds the request for long. */
    private const int BATCH = 10_000;

    /** Delete up to a batch of each table once an hour; the next hour continues where this one stopped. */
    public function purgeBefore(DateTimeImmutable $limit): void
    {
        if (! Cache::add(self::LOCK, true, self::LOCK_SECONDS)) {
            return;
        }

        $before = $limit->format('Y-m-d H:i:sP');

        foreach (['http_stats', 'health_checks'] as $table) {
            DB::delete("DELETE FROM {$table} WHERE ctid IN (SELECT ctid FROM {$table} WHERE bucket_minute < ? LIMIT ".self::BATCH.')', [$before]);
        }
    }
}
