<?php

declare(strict_types=1);

namespace Src\Shared\Application\UseCases;

use Src\Shared\Application\DTOs\HealthReport;
use Src\Shared\Domain\Contracts\DatabaseHealth;
use Src\Shared\Domain\Contracts\QueueHealth;

final readonly class CheckHealth
{
    public function __construct(
        private DatabaseHealth $database,
        private QueueHealth $queue,
    ) {}

    /**
     * Report which dependencies of the application work; if it can answer, the application itself is up. Both are always asked, so the
     * monitoring panel can say which one failed (RNF-15).
     */
    public function execute(): HealthReport
    {
        return new HealthReport($this->database->isAvailable(), $this->queue->isAvailable());
    }
}
