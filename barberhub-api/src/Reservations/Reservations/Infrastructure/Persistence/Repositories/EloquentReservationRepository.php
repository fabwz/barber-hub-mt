<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatusChange;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Reservations\Reservations\Infrastructure\Persistence\Models\ReservationModel;
use Src\Reservations\Reservations\Infrastructure\Persistence\Models\ReservationStatusChangeModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class EloquentReservationRepository implements ReservationRepository
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new reservation. */
    public function nextId(): ReservationId
    {
        return new ReservationId((string) Str::uuid7());
    }

    /**
     * Insert the reservation once; afterwards only its status changes. Its pending history entries are inserted in the
     * same transaction, so a status never changes without leaving its entry (RE-09). A collision with another reservation
     * is rejected by the exclusions of the table (ADR 0009); turning it into a domain exception is the job of the #56.
     */
    public function save(Reservation $reservation): void
    {
        $barbershopId = $this->context->current()->value;

        DB::transaction(function () use ($reservation, $barbershopId): void {
            $model = $this->ofBarbershop()->find($reservation->id->value);

            if ($model === null) {
                ReservationModel::query()->create([
                    'id' => $reservation->id->value,
                    'barbershop_id' => $barbershopId,
                    'collaborator_id' => $reservation->collaboratorId->value,
                    'service_id' => $reservation->serviceId->value,
                    'customer_membership_id' => $reservation->customerMembershipId->value,
                    'status' => $reservation->status->value,
                    'starts_at' => $reservation->interval->start,
                    'ends_at' => $reservation->interval->end,
                    'blocked_until' => $reservation->blockedUntil,
                    'price' => $reservation->price->colones,
                    'duration_minutes' => $reservation->duration->minutes,
                    'cancellation_deadline_hours' => $reservation->cancellationDeadlineHours,
                    'reschedule_count' => $reservation->rescheduleCount,
                ]);
            } else {
                $model->update(['status' => $reservation->status->value]);
            }

            foreach ($reservation->pendingChanges as $change) {
                $this->record($reservation->id, $barbershopId, $change);
            }
        });

        $reservation->markSaved();
    }

    /** Find the reservation among those of the current barbershop. */
    public function find(ReservationId $id): ?Reservation
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Insert one entry of the history. */
    private function record(ReservationId $id, string $barbershopId, ReservationStatusChange $change): void
    {
        ReservationStatusChangeModel::query()->create([
            'barbershop_id' => $barbershopId,
            'reservation_id' => $id->value,
            'from_status' => $change->from?->value,
            'to_status' => $change->to->value,
            'changed_by_user_id' => $change->author->user?->value,
            'changed_at' => $change->at,
        ]);
    }

    /**
     * Scope the query to the current barbershop; the row-level policy is the second barrier.
     *
     * @return Builder<ReservationModel>
     */
    private function ofBarbershop(): Builder
    {
        return ReservationModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** Rebuild the aggregate from its stored state. */
    private function toDomain(ReservationModel $model): Reservation
    {
        return Reservation::reconstitute(
            new ReservationId($model->id),
            new CollaboratorId($model->collaborator_id),
            new ServiceId($model->service_id),
            new CustomerMembershipId($model->customer_membership_id),
            TimeInterval::between($model->starts_at, $model->ends_at),
            $model->blocked_until,
            Money::of($model->price),
            Duration::ofMinutes($model->duration_minutes),
            $model->cancellation_deadline_hours,
            $model->reschedule_count,
            ReservationStatus::from($model->status),
        );
    }
}
