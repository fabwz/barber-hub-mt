<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** The part of the day a phrase mentions ("en la tarde"). The hours of each one are decided later (IA-07). */
enum ExtractedDayPart: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';
}
