<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Services;

use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class RangesCover
{
    /**
     * Tell whether a range falls inside the union of other ranges of the same day. Ranges that touch at an end count
     * as one, so a shift from 10:00 to 14:00 fits an opening of 09:00-12:00 followed by 12:00-15:00.
     *
     * @param  list<TimeRange>  $ranges
     */
    public static function covers(array $ranges, TimeRange $target): bool
    {
        usort($ranges, fn (TimeRange $a, TimeRange $b): int => strcmp($a->start->value, $b->start->value));

        $coveredUntil = null;

        foreach ($ranges as $range) {
            if ($coveredUntil === null) {
                if ($range->contains($target->start)) {
                    $coveredUntil = $range->end;
                }
            } elseif (! $coveredUntil->isBefore($range->start) && $coveredUntil->isBefore($range->end)) {
                $coveredUntil = $range->end;
            }

            if ($coveredUntil !== null && ! $coveredUntil->isBefore($target->end)) {
                return true;
            }
        }

        return false;
    }
}
