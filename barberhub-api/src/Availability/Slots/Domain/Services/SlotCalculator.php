<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Domain\Services;

use DateTimeImmutable;
use Src\Availability\Slots\Domain\ValueObjects\DayAgenda;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class SlotCalculator
{
    public const int STEP_MINUTES = 15;

    public const int CLEANUP_MINUTES = 5;

    public const int MIN_NOTICE_MINUTES = 60;

    public const int WINDOW_DAYS = 30;

    /**
     * Compute the free spaces of a collaborator on a day, in order of start (DI-01 to DI-03, DI-05).
     * Candidates start on the quarter hours of the clock; the service plus the cleaning must fit in a shift and in the
     * opening hours, touch no break and overlap no reservation. Touching an end is allowed. Both limits of the booking
     * window are inclusive.
     *
     * @return list<Slot>
     */
    public function forAgenda(DayAgenda $agenda, Duration $service, DateTimeImmutable $now): array
    {
        $earliest = $now->modify('+'.self::MIN_NOTICE_MINUTES.' minutes');
        $latest = $now->modify('+'.self::WINDOW_DAYS.' days');
        $block = $service->minutes + self::CLEANUP_MINUTES;
        $breaks = $this->spans($agenda->breaks);
        $slots = [];

        foreach ($this->intersect($this->merge($this->spans($agenda->openRanges)), $this->merge($this->spans($agenda->shifts))) as [$from, $to]) {
            for ($minute = $this->roundUp($from); $minute + $block <= $to; $minute += self::STEP_MINUTES) {
                $start = $agenda->date->at($minute);

                if ($start < $earliest || $start > $latest || $this->touchesBreak($breaks, $minute, $minute + $block)) {
                    continue;
                }

                $held = TimeInterval::between($start, $agenda->date->at($minute + $block));

                if (array_any($agenda->blocked, $held->overlaps(...))) {
                    continue;
                }

                $slots[] = new Slot($agenda->collaboratorId, $start, $agenda->date->at($minute + $service->minutes));
            }
        }

        return $slots;
    }

    /**
     * Pick the spaces whose start is closest to a requested one, the earlier first on a tie, and list them by start (RE-02).
     *
     * @param  list<Slot>  $slots
     * @return list<Slot>
     */
    public function nearest(array $slots, DateTimeImmutable $requested, int $limit): array
    {
        $distance = fn (Slot $slot): int => abs($slot->start->getTimestamp() - $requested->getTimestamp());

        usort($slots, fn (Slot $a, Slot $b): int => [$distance($a), $a->start->getTimestamp()] <=> [$distance($b), $b->start->getTimestamp()]);

        $closest = array_slice($slots, 0, $limit);
        usort($closest, fn (Slot $a, Slot $b): int => $a->start <=> $b->start);

        return $closest;
    }

    /** First quarter hour at or after a minute of the day. */
    private function roundUp(int $minute): int
    {
        return intdiv($minute + self::STEP_MINUTES - 1, self::STEP_MINUTES) * self::STEP_MINUTES;
    }

    /**
     * Whether the minutes from-to share some minute with a break.
     *
     * @param  list<array{int, int}>  $breaks
     */
    private function touchesBreak(array $breaks, int $from, int $to): bool
    {
        return array_any($breaks, fn (array $break): bool => $from < $break[1] && $break[0] < $to);
    }

    /**
     * Ranges as minutes of the day.
     *
     * @param  list<TimeRange>  $ranges
     * @return list<array{int, int}>
     */
    private function spans(array $ranges): array
    {
        return array_map(fn (TimeRange $range): array => [$this->minutes($range->start), $this->minutes($range->end)], $ranges);
    }

    private function minutes(TimeOfDay $time): int
    {
        return (int) substr($time->value, 0, 2) * 60 + (int) substr($time->value, 3, 2);
    }

    /**
     * Order spans and join the ones that touch or overlap, so a space can cross the line between two adjacent ranges.
     *
     * @param  list<array{int, int}>  $spans
     * @return list<array{int, int}>
     */
    private function merge(array $spans): array
    {
        usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];

        foreach ($spans as [$from, $to]) {
            $last = array_key_last($merged);

            if ($last !== null && $from <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $to);
            } else {
                $merged[] = [$from, $to];
            }
        }

        return $merged;
    }

    /**
     * The minutes two sets of merged spans share.
     *
     * @param  list<array{int, int}>  $first
     * @param  list<array{int, int}>  $second
     * @return list<array{int, int}>
     */
    private function intersect(array $first, array $second): array
    {
        $shared = [];

        foreach ($first as [$fromA, $toA]) {
            foreach ($second as [$fromB, $toB]) {
                if (max($fromA, $fromB) < min($toA, $toB)) {
                    $shared[] = [max($fromA, $fromB), min($toA, $toB)];
                }
            }
        }

        return $shared;
    }
}
