<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\Clock;

/**
 * A clock the test can move. The router keeps the controller it built for a route, so a test that changes the time between
 * requests moves this one clock instead of binding a new one.
 */
final class MutableClock implements Clock
{
    /** Start at the given instant. */
    public function __construct(private DateTimeImmutable $now) {}

    /** The current instant of the test. */
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /** Move the clock to another instant. */
    public function moveTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
