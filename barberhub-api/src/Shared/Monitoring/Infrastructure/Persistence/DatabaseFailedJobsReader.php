<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Src\Shared\Monitoring\Application\Contracts\FailedJobsReader;
use Src\Shared\Monitoring\Application\DTOs\FailedJob;
use Src\Shared\Monitoring\Application\DTOs\FailedJobGroup;
use stdClass;

/**
 * Reads `failed_jobs`. The name of the job and the class of the exception are taken out INSIDE the query, so the payload (which can carry
 * contact data of a notice) and the exception text (which can carry a host or a credential) never reach PHP, the DTOs or the page (RNF-24, SE-03).
 */
final class DatabaseFailedJobsReader implements FailedJobsReader
{
    /** The columns every query selects: the job is the display name of the payload when it is valid JSON; the class is what comes before the first `:`. */
    private const string PROJECTION = <<<'SQL'
        uuid, queue, failed_at,
        left(coalesce(CASE WHEN pg_input_is_valid(payload, 'jsonb') THEN payload::jsonb ->> 'displayName' END, ''), 200) AS job,
        coalesce(substring(exception FROM '^[A-Za-z0-9_\\]+'), '') AS exception_class
        SQL;

    /** @return list<FailedJobGroup> */
    public function groups(int $limit): array
    {
        $rows = DB::select(
            'SELECT job, queue, count(*)::int AS failures, max(failed_at) AS last_failed_at, (array_agg(exception_class ORDER BY failed_at DESC))[1] AS exception_class '
            .'FROM (SELECT '.self::PROJECTION.' FROM failed_jobs) AS failed '
            .'GROUP BY job, queue ORDER BY max(failed_at) DESC, job LIMIT ?',
            [$limit],
        );

        return array_values(array_map(
            fn (stdClass $row): FailedJobGroup => new FailedJobGroup((string) $row->job, (string) $row->queue, (int) $row->failures, (string) $row->last_failed_at, (string) $row->exception_class),
            $rows,
        ));
    }

    /** @return list<FailedJob> */
    public function recent(int $limit): array
    {
        $rows = DB::select('SELECT '.self::PROJECTION.' FROM failed_jobs ORDER BY failed_at DESC, id DESC LIMIT ?', [$limit]);

        return array_values(array_map(
            fn (stdClass $row): FailedJob => new FailedJob((string) $row->uuid, (string) $row->job, (string) $row->queue, (string) $row->failed_at, (string) $row->exception_class),
            $rows,
        ));
    }
}
