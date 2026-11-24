<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use Src\Notifications\Notices\Application\Contracts\ReminderPreferences;

/** The choice of each customer role about the reminders; whoever did not choose wants them (NO-10). */
final class ChosenReminderPreferences implements ReminderPreferences
{
    /** @var array<string, bool> */
    public array $choices = [];

    /** The choice, or true when there is none. */
    public function isEnabledFor(string $membershipId): bool
    {
        return $this->choices[$membershipId] ?? true;
    }
}
