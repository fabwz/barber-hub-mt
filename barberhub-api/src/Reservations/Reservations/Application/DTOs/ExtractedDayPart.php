<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** The part of the day a phrase mentions ("en la tarde"), with the hours the rules give it (IA-07). */
enum ExtractedDayPart: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case Evening = 'evening';

    /** First minute of the part of the day, as `HH:MM` (IA-07). */
    public function startsAt(): string
    {
        return match ($this) {
            self::Morning => '06:00',
            self::Afternoon => '12:00',
            self::Evening => '18:00',
        };
    }

    /** Last minute of the part of the day, as `HH:MM` (IA-07). */
    public function endsAt(): string
    {
        return match ($this) {
            self::Morning => '11:59',
            self::Afternoon => '17:59',
            self::Evening => '23:59',
        };
    }
}
