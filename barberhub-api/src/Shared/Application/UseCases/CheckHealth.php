<?php

declare(strict_types=1);

namespace Src\Shared\Application\UseCases;

use Src\Shared\Domain\Contracts\DatabaseHealth;
use Src\Shared\Domain\Contracts\QueueHealth;

final readonly class CheckHealth
{
    public function __construct(
        private DatabaseHealth $database,
        private QueueHealth $queue,
    ) {}

    /** Tell whether the dependencies of the application work; if it can answer, the application itself is up. */
    public function execute(): bool
    {
        return $this->database->isAvailable() && $this->queue->isAvailable();
    }
}
