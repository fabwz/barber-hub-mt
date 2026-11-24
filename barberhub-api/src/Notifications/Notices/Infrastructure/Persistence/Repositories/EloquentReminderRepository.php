<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Notifications\Notices\Domain\Contracts\ReminderRepository;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Src\Notifications\Notices\Infrastructure\Persistence\Models\ReservationReminderModel;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class EloquentReminderRepository implements ReminderRepository
{
    private const string INSTANT = 'Y-m-d H:i:sP';

    /** Receive the port that tells which barbershop the operation belongs to: every statement filters by it, besides the row policy. */
    public function __construct(private BarbershopContext $context) {}

    /** Insert the reminder, or move it when it is due at another instant; a reminder due at this very instant is not touched. */
    public function schedule(string $reservationId, ReminderKind $kind, DateTimeImmutable $at): bool
    {
        $changed = DB::affectingStatement(
            <<<'SQL'
                INSERT INTO reservation_reminders (id, barbershop_id, reservation_id, kind, scheduled_for)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (barbershop_id, reservation_id, kind)
                DO UPDATE SET scheduled_for = EXCLUDED.scheduled_for, sent_at = NULL
                WHERE reservation_reminders.scheduled_for <> EXCLUDED.scheduled_for
                SQL,
            [(string) Str::uuid7(), $this->context->current()->value, $reservationId, $kind->value, $at->format(self::INSTANT)],
        );

        return $changed > 0;
    }

    /** Delete the reminder of the kind, if there is one. */
    public function forget(string $reservationId, ReminderKind $kind): void
    {
        $this->of($reservationId, $kind)->delete();
    }

    /** Mark it as sent in a single statement, so two tasks that run together cannot both win. */
    public function claim(string $reservationId, ReminderKind $kind, DateTimeImmutable $at, DateTimeImmutable $now): bool
    {
        return $this->of($reservationId, $kind)
            ->where('scheduled_for', $at->format(self::INSTANT))
            ->whereNull('sent_at')
            ->update(['sent_at' => $now->format(self::INSTANT)]) > 0;
    }

    /**
     * The query of the reminder of a reservation and a kind, in the current barbershop.
     *
     * @return Builder<ReservationReminderModel>
     */
    private function of(string $reservationId, ReminderKind $kind): Builder
    {
        return ReservationReminderModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('reservation_id', $reservationId)
            ->where('kind', $kind->value);
    }
}
