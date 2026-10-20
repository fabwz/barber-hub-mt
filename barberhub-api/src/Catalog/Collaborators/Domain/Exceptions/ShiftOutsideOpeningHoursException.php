<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Catalog\Collaborators\Domain\ValueObjects\ScheduleSection;

final class ShiftOutsideOpeningHoursException extends ScheduleEntryException
{
    /** Build the exception for the shift that does not fall inside the opening hours of its day (HO-02). */
    public static function at(int $index): self
    {
        return self::atEntry('collaborators.shift_outside_opening_hours', ScheduleSection::Shifts, $index);
    }
}
