<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\Contracts;

use DateTimeImmutable;
use Src\Shared\Monitoring\Application\DTOs\ErrorGroup;
use Src\Shared\Monitoring\Application\DTOs\RouteTiming;

/** Reads the statistics of the requests. */
interface RequestStatsReader
{
    /**
     * The requests that threw an exception since the instant, grouped by class, route and class of status, the most frequent first.
     *
     * @return list<ErrorGroup>
     */
    public function errorsSince(DateTimeImmutable $since): array;

    /**
     * How each route answered since the instant, the slowest on average first.
     *
     * @return list<RouteTiming>
     */
    public function timingsSince(DateTimeImmutable $since): array;
}
