<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationFilter;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatusChange;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Reservations\Reservations\Infrastructure\Persistence\Models\ReservationModel;
use Src\Reservations\Reservations\Infrastructure\Persistence\Models\ReservationStatusChangeModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Shared\Domain\ValueObjects\UserId;
use Src\Shared\Infrastructure\Persistence\EloquentPages;

final readonly class EloquentReservationRepository implements ReservationRepository
{
    private const string EXCLUSION_VIOLATION = '23P01';

    private const string CUSTOMER_EXCLUSION = 'reservations_customer_no_overlap_excl';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new reservation. */
    public function nextId(): ReservationId
    {
        return new ReservationId((string) Str::uuid7());
    }

    /**
     * Insert the reservation once; afterwards its status changes and, when it is rescheduled, its space (collaborator, start,
     * end, blocked-until and the count of reschedules). Its pending history entries are inserted in the
     * same transaction, so a status never changes without leaving its entry (RE-09). A collision with another reservation
     * is rejected by the exclusions of the table (ADR 0009) and translated here: the one of the collaborator is a space
     * that is not available (RE-01) and the one of the customer is an overlap of their own reservations (RE-06).
     */
    public function save(Reservation $reservation): void
    {
        $barbershopId = $this->context->current()->value;

        try {
            $this->store($reservation, $barbershopId);
        } catch (QueryException $exception) {
            if ($exception->getCode() !== self::EXCLUSION_VIOLATION) {
                throw $exception;
            }

            throw str_contains($exception->getMessage(), self::CUSTOMER_EXCLUSION) ? OverlappingReservationException::create() : SlotUnavailableException::create();
        }

        $reservation->markSaved();
    }

    /** Tell whether the customer has a confirmed reservation that shares some minute with the interval. */
    public function hasConfirmedOverlapping(CustomerMembershipId $customer, TimeInterval $interval, ?ReservationId $ignoring = null): bool
    {
        return $this->ofBarbershop()
            ->when($ignoring !== null, fn (Builder $query): Builder => $query->where('id', '!=', $ignoring?->value))
            ->where('customer_membership_id', $customer->value)
            ->where('status', ReservationStatus::Confirmed->value)
            ->where('starts_at', '<', $interval->end)
            ->where('ends_at', '>', $interval->start)
            ->exists();
    }

    /** Write the reservation and its pending entries in one transaction (a savepoint inside the one of the request). */
    private function store(Reservation $reservation, string $barbershopId): void
    {
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
                $model->update([
                    'status' => $reservation->status->value,
                    'collaborator_id' => $reservation->collaboratorId->value,
                    'starts_at' => $reservation->interval->start,
                    'ends_at' => $reservation->interval->end,
                    'blocked_until' => $reservation->blockedUntil,
                    'reschedule_count' => $reservation->rescheduleCount,
                ]);
            }

            foreach ($reservation->pendingChanges as $change) {
                $this->record($reservation->id, $barbershopId, $change);
            }
        });
    }

    /** Find the reservation among those of the current barbershop. */
    public function find(ReservationId $id): ?Reservation
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Find the reservation among those of the current barbershop, locking its row. */
    public function findForUpdate(ReservationId $id): ?Reservation
    {
        $model = $this->ofBarbershop()->lockForUpdate()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * Read the page with two statements, the count and the rows, ordered by start. The customer and the collaborator come
     * from the filter, never from the request, so each listing decides its own scope.
     *
     * @return Page<Reservation>
     */
    public function page(ReservationFilter $filter, PageRequest $page): Page
    {
        $query = $this->ofBarbershop();

        if ($filter->customer !== null) {
            $query->where('customer_membership_id', $filter->customer->value);
        }

        if ($filter->collaborator !== null) {
            $query->where('collaborator_id', $filter->collaborator->value);
        }

        if ($filter->status !== null) {
            $query->where('status', $filter->status->value);
        }

        if (($window = $filter->window()) !== null) {
            $query->where('starts_at', '>=', $window->start)->where('starts_at', '<', $window->end);
        }

        return EloquentPages::of($query->orderBy('starts_at')->orderBy('id'), $page, fn (ReservationModel $model): Reservation => $this->toDomain($model));
    }

    /**
     * Read the entries of the history of the reservation in the order they happened, among those of the current barbershop.
     *
     * @return list<ReservationStatusChange>
     */
    public function history(ReservationId $id): array
    {
        $entries = ReservationStatusChangeModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('reservation_id', $id->value)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();

        return array_values($entries->map(fn (ReservationStatusChangeModel $entry): ReservationStatusChange => new ReservationStatusChange(
            $entry->from_status === null ? null : ReservationStatus::from($entry->from_status),
            ReservationStatus::from($entry->to_status),
            $entry->changed_by_user_id === null ? Author::system() : Author::person(new UserId($entry->changed_by_user_id)),
            $entry->changed_at,
        ))->all());
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
