<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Persistence\Queries;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Src\Reservations\Reservations\Application\Contracts\ReservationCounter;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Infrastructure\Persistence\Models\ReservationModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class EloquentReservationCounter implements ReservationCounter
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Count with one grouped query the reservations of some collaborators that start inside the window and are confirmed,
     * completed, no-show or temporary with the payment window still open at `$now` (DI-04, RE-05).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, int>
     */
    public function startingIn(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array
    {
        $rows = ReservationModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->whereIn('collaborator_id', $collaboratorIds)
            ->where('starts_at', '>=', $window->start)
            ->where('starts_at', '<', $window->end)
            ->where(fn (Builder $counted): Builder => $counted
                ->whereIn('status', [ReservationStatus::Confirmed->value, ReservationStatus::Completed->value, ReservationStatus::NoShow->value])
                ->orWhere(fn (Builder $temporary): Builder => $temporary
                    ->where('status', ReservationStatus::Temporary->value)
                    ->where(fn (Builder $open): Builder => $open->whereNull('expires_at')->orWhere('expires_at', '>', $now))))
            ->groupBy('collaborator_id')
            ->selectRaw('collaborator_id, count(*) as total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('collaborator_id')] = (int) $row->getAttribute('total');
        }

        return $counts;
    }
}
