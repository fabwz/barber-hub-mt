<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Catalog\Collaborators\Domain\ValueObjects\ScheduleSection;

final class ShiftsOverlapException extends ScheduleEntryException
{
    /** Build the exception for the shift that collides with another of the same day. */
    public static function at(int $index): self
    {
        return self::atEntry('collaborators.shifts_overlap', ScheduleSection::Shifts, $index);
    }
}
