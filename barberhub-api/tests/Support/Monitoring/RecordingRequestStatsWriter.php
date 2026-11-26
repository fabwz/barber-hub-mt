<?php

declare(strict_types=1);

namespace Tests\Support\Monitoring;

use DateTimeImmutable;
use Src\Shared\Monitoring\Domain\Contracts\RequestStatsWriter;

/** Keeps what the use case hands to the statistics, to check it without a database. */
final class RecordingRequestStatsWriter implements RequestStatsWriter
{
    /** @var list<array{string, string, string, int, string, int}> minute, method, route, status class, exception class, duration */
    public array $calls = [];

    public function record(DateTimeImmutable $minute, string $method, string $routePattern, int $statusClass, string $exceptionClass, int $durationMs): void
    {
        $this->calls[] = [$minute->format('Y-m-d H:i:s'), $method, $routePattern, $statusClass, $exceptionClass, $durationMs];
    }
}
