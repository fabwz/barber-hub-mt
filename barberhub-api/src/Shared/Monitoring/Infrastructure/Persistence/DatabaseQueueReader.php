<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Persistence;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Shared\Monitoring\Application\Contracts\QueueReader;
use Src\Shared\Monitoring\Application\DTOs\QueueCounts;

/** Reads `jobs`, the table of the `database` queue driver (RD-11, provisional). It selects counts and one timestamp: never `payload`. */
final class DatabaseQueueReader implements QueueReader
{
    public function counts(string $queue, DateTimeImmutable $now): QueueCounts
    {
        $at = $now->getTimestamp();

        $row = DB::selectOne(<<<'SQL'
            SELECT count(*) FILTER (WHERE reserved_at IS NULL AND available_at <= ?) AS pending,
                   count(*) FILTER (WHERE reserved_at IS NULL AND available_at > ?) AS delayed,
                   count(*) FILTER (WHERE reserved_at IS NOT NULL) AS reserved,
                   min(available_at) FILTER (WHERE reserved_at IS NULL AND available_at <= ?) AS oldest
            FROM jobs
            WHERE queue = ?
            SQL, [$at, $at, $at, $queue]);

        return new QueueCounts(
            (int) ($row->pending ?? 0),
            (int) ($row->delayed ?? 0),
            (int) ($row->reserved ?? 0),
            isset($row->oldest) ? max(0, $at - (int) $row->oldest) : 0,
        );
    }
}
