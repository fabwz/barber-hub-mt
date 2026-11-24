<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

/** The choice of a customer about the reminders by email (RF-47, NO-10). */
interface ReminderPreferences
{
    /** Whether the person of the customer role wants the reminders; they do until they say otherwise. */
    public function isEnabledFor(string $membershipId): bool;
}
