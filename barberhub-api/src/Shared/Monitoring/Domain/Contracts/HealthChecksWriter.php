<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Domain\Contracts;

use DateTimeImmutable;

interface HealthChecksWriter
{
    /**
     * Count one answer of the health route in its minute, so there is one row per minute and result.
     *
     * @param  DateTimeImmutable  $minute  The minute of the answer, with the seconds at zero.
     * @param  DateTimeImmutable  $at  The instant of the answer.
     */
    public function record(DateTimeImmutable $minute, DateTimeImmutable $at, bool $databaseOk, bool $queueOk, string $aiStatus): void;
}
