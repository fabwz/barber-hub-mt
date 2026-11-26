<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Monitoring\Domain\Contracts\RequestStatsWriter;

/** Adds a finished request to the statistics of its minute (RNF-15). */
final readonly class RecordRequest
{
    /** What the panel shows for a request that matched no route, so a scanner cannot create a row per invented URL. */
    public const string NO_ROUTE = '(sin ruta)';

    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    private const int MAX_ROUTE = 200;

    private const int MAX_EXCEPTION = 200;

    public function __construct(private Clock $clock, private RequestStatsWriter $stats) {}

    /**
     * Record one request. The route is its PATTERN (`api/v1/barberias/{barberia}/reservas`), never the URL, and the exception is its CLASS.
     *
     * @param  string|null  $routePattern  Null when no route matched.
     * @param  string|null  $exceptionClass  Null when nothing was thrown.
     */
    public function execute(string $method, ?string $routePattern, int $status, ?string $exceptionClass, int $durationMs): void
    {
        $now = $this->clock->now();
        $method = strtoupper($method);

        $this->stats->record(
            $now->setTime((int) $now->format('G'), (int) $now->format('i')),
            in_array($method, self::METHODS, true) ? $method : 'OTHER',
            $routePattern === null || $routePattern === '' ? self::NO_ROUTE : substr($routePattern, 0, self::MAX_ROUTE),
            max(1, min(5, intdiv($status, 100))),
            substr($exceptionClass ?? '', 0, self::MAX_EXCEPTION),
            max(0, $durationMs),
        );
    }
}
