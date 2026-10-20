<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\ValueObjects;

use Src\Catalog\Collaborators\Domain\Exceptions\BreakOutsideShiftException;
use Src\Catalog\Collaborators\Domain\Exceptions\BreaksOverlapException;
use Src\Catalog\Collaborators\Domain\Exceptions\ShiftsOverlapException;
use Src\Catalog\Collaborators\Domain\Services\RangesCover;
use Src\Shared\Domain\ValueObjects\TimeRange;

/** The weekly working hours of a collaborator: the shifts they work and the breaks they take inside them (HO-02). */
final readonly class WorkSchedule
{
    /**
     * @param  list<Shift>  $shifts
     * @param  list<BreakPeriod>  $breaks
     */
    private function __construct(
        public array $shifts,
        public array $breaks,
    ) {}

    /** A schedule without shifts: the collaborator works no day. */
    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Rebuild a schedule from its stored state, which was validated when it was saved.
     *
     * @param  list<Shift>  $shifts
     * @param  list<BreakPeriod>  $breaks
     */
    public static function reconstitute(array $shifts, array $breaks): self
    {
        return new self($shifts, $breaks);
    }

    /**
     * Accept shifts that do not overlap on a day and breaks that neither overlap nor leave the shifts of their day.
     * The error points at the later entry of a pair that collides, or at the break that is not covered.
     *
     * @param  list<Shift>  $shifts
     * @param  list<BreakPeriod>  $breaks
     */
    public static function of(array $shifts, array $breaks): self
    {
        foreach ($shifts as $index => $shift) {
            if (array_any(array_slice($shifts, 0, $index), $shift->overlaps(...))) {
                throw ShiftsOverlapException::at($index);
            }
        }

        foreach ($breaks as $index => $break) {
            if (array_any(array_slice($breaks, 0, $index), $break->overlaps(...))) {
                throw BreaksOverlapException::at($index);
            }

            $dayShifts = array_map(
                fn (Shift $shift): TimeRange => $shift->range,
                array_filter($shifts, fn (Shift $shift): bool => $shift->day->equals($break->day)),
            );

            if (! RangesCover::covers(array_values($dayShifts), $break->range)) {
                throw BreakOutsideShiftException::at($index);
            }
        }

        return new self($shifts, $breaks);
    }
}
