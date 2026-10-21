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
     * Every temporary reservation counts: the condition "not expired" is added when `expires_at` exists (#62).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window, ?string $ignoringReservationId = null): array
    {
        $rows = ReservationModel::query()
            ->when($ignoringReservationId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $ignoringReservationId))
            ->where('barbershop_id', $this->context->current()->value)
            ->whereIn('collaborator_id', $collaboratorIds)
            ->whereIn('status', [ReservationStatus::Temporary->value, ReservationStatus::Confirmed->value])
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
