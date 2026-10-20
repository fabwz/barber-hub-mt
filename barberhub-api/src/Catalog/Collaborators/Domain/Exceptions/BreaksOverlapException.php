<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\Exceptions;

use Src\Catalog\Collaborators\Domain\ValueObjects\ScheduleSection;

final class BreaksOverlapException extends ScheduleEntryException
{
    /** Build the exception for the break that collides with another of the same day. */
    public static function at(int $index): self
    {
        return self::atEntry('collaborators.breaks_overlap', ScheduleSection::Breaks, $index);
    }
}
