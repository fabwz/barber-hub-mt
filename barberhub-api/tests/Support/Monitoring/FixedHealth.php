<?php

declare(strict_types=1);

namespace Tests\Support\Monitoring;

use Src\Shared\Domain\Contracts\DatabaseHealth;
use Src\Shared\Domain\Contracts\QueueHealth;

/**
 * A dependency of the health route that works or fails as the test decides, so the real database is never asked. The router keeps the
 * controller it built for a route, so a test that changes the answer between requests flips this one object instead of binding a new one.
 */
final class FixedHealth implements DatabaseHealth, QueueHealth
{
    public function __construct(public bool $available) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }
}
