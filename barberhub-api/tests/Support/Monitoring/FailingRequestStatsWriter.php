<?php

declare(strict_types=1);

namespace Tests\Support\Monitoring;

use DateTimeImmutable;
use RuntimeException;
use Src\Shared\Monitoring\Domain\Contracts\RequestStatsWriter;

/** A record that always fails, with a message that carries what must never be logged, to prove a failure of the monitoring changes no answer. */
final class FailingRequestStatsWriter implements RequestStatsWriter
{
    public const string SECRET = 'host=db.interno password=hunter2';

    public function record(DateTimeImmutable $minute, string $method, string $routePattern, int $statusClass, string $exceptionClass, int $durationMs): void
    {
        throw new RuntimeException(self::SECRET);
    }
}
