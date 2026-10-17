<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use DateTimeImmutable;
use Src\Shared\Domain\Exceptions\InvalidValueException;

/** Half-open interval: the start belongs to it, the end does not. */
final readonly class TimeInterval
{
    private const int SECONDS_PER_MINUTE = 60;

    private function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
    ) {}

    /** Build an interval whose start is strictly before its end. */
    public static function between(DateTimeImmutable $start, DateTimeImmutable $end): self
    {
        if ($start >= $end) {
            throw new InvalidValueException('shared.interval_start_not_before_end');
        }

        return new self($start, $end);
    }

    /** Whether both intervals share any instant; touching at an end does not count. */
    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    /** Length in whole minutes; an interval under one minute is not a valid duration. */
    public function duration(): Duration
    {
        $seconds = $this->end->getTimestamp() - $this->start->getTimestamp();

        return Duration::ofMinutes(intdiv($seconds, self::SECONDS_PER_MINUTE));
    }
}
