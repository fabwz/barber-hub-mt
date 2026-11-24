<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Domain\ValueObjects;

/** The two reminders of a confirmed reservation: one a day before and one two hours before it starts (NO-02). */
enum ReminderKind: string
{
    case DayBefore = '24h';
    case TwoHoursBefore = '2h';

    /** Hours before the start of the reservation at which the reminder is due. */
    public function hoursBefore(): int
    {
        return match ($this) {
            self::DayBefore => 24,
            self::TwoHoursBefore => 2,
        };
    }
}
