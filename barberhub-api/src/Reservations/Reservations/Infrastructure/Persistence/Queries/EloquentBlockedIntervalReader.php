<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Persistence\Queries;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Src\Reservations\Reservations\Application\Contracts\BlockedIntervalReader;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Infrastructure\Persistence\Models\ReservationModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class EloquentBlockedIntervalReader implements BlockedIntervalReader
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Read the intervals of the temporary and confirmed reservations of some collaborators that overlap a window.
     * A temporary reservation counts only while its payment window is open at `$now`, even if nobody marked it expired yet
     * (DI-05, RE-05); a confirmed one always counts.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
    {
        $rows = ReservationModel::query()
            ->when($ignoringReservationId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $ignoringReservationId))
            ->where('barbershop_id', $this->context->current()->value)
            ->whereIn('collaborator_id', $collaboratorIds)
            ->where(fn (Builder $held): Builder => $held
                ->where('status', ReservationStatus::Confirmed->value)
                ->orWhere(fn (Builder $temporary): Builder => $temporary
                    ->where('status', ReservationStatus::Temporary->value)
                    ->where(fn (Builder $open): Builder => $open->whereNull('expires_at')->orWhere('expires_at', '>', $now))))
            ->where('starts_at', '<', $window->end)
            ->where('blocked_until', '>', $window->start)
            ->orderBy('starts_at')
            ->get(['collaborator_id', 'starts_at', 'blocked_until']);

        $intervals = [];

        foreach ($rows as $row) {
            $intervals[$row->collaborator_id][] = TimeInterval::between(
                DateTimeImmutable::createFromInterface($row->starts_at),
                DateTimeImmutable::createFromInterface($row->blocked_until),
            );
        }

        return $intervals;
    }
}
