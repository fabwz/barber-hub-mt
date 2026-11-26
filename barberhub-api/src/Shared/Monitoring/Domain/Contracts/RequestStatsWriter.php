<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Domain\Contracts;

use DateTimeImmutable;

interface RequestStatsWriter
{
    /**
     * Add one request to the statistics of its minute. Only these values are kept: never the URL, the user, the body or the message of an error.
     *
     * @param  DateTimeImmutable  $minute  The minute of the request, with the seconds at zero.
     * @param  string  $exceptionClass  The class of the exception thrown, or an empty string when none was.
     */
    public function record(DateTimeImmutable $minute, string $method, string $routePattern, int $statusClass, string $exceptionClass, int $durationMs): void;
}
