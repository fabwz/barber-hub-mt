<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use DateTimeImmutable;
use Src\Notifications\Notices\Domain\Contracts\ReminderRepository;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/** The reminders in memory, with the same rules as the table: one row per reservation and kind, moved by a new instant, claimed once. */
final class InMemoryReminderRepository implements ReminderRepository
{
    /** @var array<string, array{at: DateTimeImmutable, sent: bool}> */
    private array $rows = [];

    /** Insert, move to a new instant (unsent again) or leave as it is. */
    public function schedule(string $reservationId, ReminderKind $kind, DateTimeImmutable $at): bool
    {
        $key = $reservationId.$kind->value;

        if (isset($this->rows[$key]) && $this->rows[$key]['at'] == $at) {
            return false;
        }

        $this->rows[$key] = ['at' => $at, 'sent' => false];

        return true;
    }

    /** Delete the row. */
    public function forget(string $reservationId, ReminderKind $kind): void
    {
        unset($this->rows[$reservationId.$kind->value]);
    }

    /** Mark it as sent when it is unsent and due at that instant. */
    public function claim(string $reservationId, ReminderKind $kind, DateTimeImmutable $at, DateTimeImmutable $now): bool
    {
        $key = $reservationId.$kind->value;

        if (! isset($this->rows[$key]) || $this->rows[$key]['sent'] || $this->rows[$key]['at'] != $at) {
            return false;
        }

        $this->rows[$key]['sent'] = true;

        return true;
    }

    /** The instant of the reminder, or null when there is none. */
    public function dueAt(string $reservationId, ReminderKind $kind): ?DateTimeImmutable
    {
        return $this->rows[$reservationId.$kind->value]['at'] ?? null;
    }

    /** Whether the reminder was sent. */
    public function wasSent(string $reservationId, ReminderKind $kind): bool
    {
        return $this->rows[$reservationId.$kind->value]['sent'] ?? false;
    }

    /** How many reminders are stored. */
    public function count(): int
    {
        return count($this->rows);
    }
}
