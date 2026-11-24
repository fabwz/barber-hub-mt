<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Domain\Services;

use DateTimeImmutable;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderDue;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/** Which reminders of a reservation still fit, and when each one is due (NO-02). */
final class ReminderSchedule
{
    /**
     * The reminders due from `$now` on, for a reservation that starts at `$startsAt`. A reminder whose instant already passed is left out: a
     * reservation made with less than 24 h of notice only has the 2 h one, and one made with less than 2 h has none. The clock enters as a
     * parameter, the domain never asks for the time.
     *
     * @return list<ReminderDue>
     */
    public static function from(DateTimeImmutable $startsAt, DateTimeImmutable $now): array
    {
        $due = [];

        foreach (ReminderKind::cases() as $kind) {
            $at = $startsAt->modify("-{$kind->hoursBefore()} hours");

            if ($at >= $now) {
                $due[] = new ReminderDue($kind, $at);
            }
        }

        return $due;
    }
}
