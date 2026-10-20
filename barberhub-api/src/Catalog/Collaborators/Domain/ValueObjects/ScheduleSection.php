<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\ValueObjects;

/** The two lists of a weekly schedule, so an error can point at the entry that caused it. */
enum ScheduleSection: string
{
    case Shifts = 'shifts';
    case Breaks = 'breaks';
}
