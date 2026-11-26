<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

/** The choices of an account about the notices it receives by email (RF-47). */
final readonly class NoticePreferences
{
    /** Carry whether the account wants the reminders of its reservations. */
    public function __construct(public bool $remindersEnabled) {}
}
