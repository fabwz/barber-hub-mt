<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Domain\Contracts;

use DateTimeImmutable;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/** The reminders of the reservations of the current barbershop, and the guarantee that none is sent twice (NO-03). */
interface ReminderRepository
{
    /**
     * Record that a reminder is due at an instant. A new one is inserted; one that exists for another instant is moved to this one and
     * becomes unsent again (a reschedule); one that already has this instant is left as it is, so repeating the call, or the event that
     * causes it, never makes a sent reminder due again. Answers whether something changed.
     */
    public function schedule(string $reservationId, ReminderKind $kind, DateTimeImmutable $at): bool;

    /** Forget the reminder of a kind, if there is one: the task that was left for it finds no row and does nothing. */
    public function forget(string $reservationId, ReminderKind $kind): void;

    /** Mark the reminder as sent, only if it is still unsent and due at that instant. Answers false when someone else did, or it changed. */
    public function claim(string $reservationId, ReminderKind $kind, DateTimeImmutable $at, DateTimeImmutable $now): bool;
}
