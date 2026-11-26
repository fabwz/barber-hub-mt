<?php

declare(strict_types=1);

namespace Src\Shared\Monitoring\Application\UseCases;

use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Monitoring\Domain\Contracts\HealthChecksWriter;

/** Counts an answer of the health route in its minute, so a failure stays visible after it is fixed (RNF-15, RNF-04). */
final readonly class RecordHealthCheck
{
    public function __construct(private Clock $clock, private HealthChecksWriter $checks) {}

    /** Record the answer: which dependency worked, and the public state of the language provider. */
    public function execute(bool $databaseOk, bool $queueOk, string $aiStatus): void
    {
        $now = $this->clock->now();

        $this->checks->record($now->setTime((int) $now->format('G'), (int) $now->format('i')), $now, $databaseOk, $queueOk, $aiStatus);
    }
}
