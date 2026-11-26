<?php

declare(strict_types=1);

namespace Tests\Support\Monitoring;

use DateTimeImmutable;
use Src\Shared\Monitoring\Domain\Contracts\HealthChecksWriter;

/** Keeps what the use case hands to the health record, to check it without a database. */
final class RecordingHealthChecksWriter implements HealthChecksWriter
{
    /** @var list<array{string, string, bool, bool, string}> minute, instant, database, queue, state of the AI */
    public array $calls = [];

    public function record(DateTimeImmutable $minute, DateTimeImmutable $at, bool $databaseOk, bool $queueOk, string $aiStatus): void
    {
        $this->calls[] = [$minute->format('Y-m-d H:i:s'), $at->format('Y-m-d H:i:s'), $databaseOk, $queueOk, $aiStatus];
    }
}
