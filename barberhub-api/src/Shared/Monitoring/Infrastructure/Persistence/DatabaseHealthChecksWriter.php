<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Shared\Monitoring\Domain\Contracts\HealthChecksWriter;

final class DatabaseHealthChecksWriter implements HealthChecksWriter
{
    /** One upsert per minute and result: asking the public route a thousand times in a minute still leaves one row. */
    public function record(DateTimeImmutable $minute, DateTimeImmutable $at, bool $databaseOk, bool $queueOk, string $aiStatus): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO health_checks (bucket_minute, database_ok, queue_ok, healthy, ai_status, checks, last_checked_at)
            VALUES (?, ?, ?, ?, ?, 1, ?)
            ON CONFLICT (bucket_minute, database_ok, queue_ok) DO UPDATE SET
                checks = health_checks.checks + 1,
                ai_status = EXCLUDED.ai_status,
                last_checked_at = EXCLUDED.last_checked_at
            SQL, [$minute->format('Y-m-d H:i:sP'), $databaseOk, $queueOk, $databaseOk && $queueOk, substr($aiStatus, 0, 20), $at->format('Y-m-d H:i:sP')]);
    }
}
