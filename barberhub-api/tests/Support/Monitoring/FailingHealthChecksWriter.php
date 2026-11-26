<?php

declare(strict_types=1);

namespace Tests\Support\Monitoring;

use DateTimeImmutable;
use RuntimeException;
use Src\Shared\Monitoring\Domain\Contracts\HealthChecksWriter;

/** A record of the health route that always fails, with a message that carries what must never be logged. */
final class FailingHealthChecksWriter implements HealthChecksWriter
{
    public const string SECRET = 'host=db.interno password=hunter2';

    public function record(DateTimeImmutable $minute, DateTimeImmutable $at, bool $databaseOk, bool $queueOk, string $aiStatus): void
    {
        throw new RuntimeException(self::SECRET);
    }
}
