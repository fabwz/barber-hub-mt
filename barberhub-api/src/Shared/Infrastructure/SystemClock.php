<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Src\Shared\Domain\Contracts\Clock;

final readonly class SystemClock implements Clock
{
    public function __construct(private DateTimeZone $timezone) {}

    /** Current instant in the configured time zone. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
