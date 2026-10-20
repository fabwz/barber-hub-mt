<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Catalog\Collaborators\Domain\ValueObjects\ScheduleSection;

final class BreakOutsideShiftException extends ScheduleEntryException
{
    /** Build the exception for the break that does not fall inside the shifts of its day (HO-02). */
    public static function at(int $index): self
    {
        return self::atEntry('collaborators.break_outside_shift', ScheduleSection::Breaks, $index);
    }
}
