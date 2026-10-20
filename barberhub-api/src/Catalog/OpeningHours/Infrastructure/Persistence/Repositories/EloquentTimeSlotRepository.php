<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Catalog\OpeningHours\Domain\Exceptions\TimeSlotOverlapsException;
use Src\Catalog\OpeningHours\Domain\ValueObjects\TimeSlotId;
use Src\Catalog\OpeningHours\Infrastructure\Persistence\Models\TimeSlotModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class EloquentTimeSlotRepository implements TimeSlotRepository
{
    private const string EXCLUSION_VIOLATION = '23P01';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new slot. */
    public function nextId(): TimeSlotId
    {
        return new TimeSlotId((string) Str::uuid7());
    }

    /** Insert the slot inside a savepoint and translate the exclusion violation of the overlapping range (ADR 0009). */
    public function save(TimeSlot $slot): void
    {
        try {
            DB::transaction(fn () => TimeSlotModel::query()->updateOrCreate(
                ['id' => $slot->id->value, 'barbershop_id' => $this->context->current()->value],
                [
                    'day_of_week' => $slot->day->value,
                    'opens_at' => $slot->range->start->value,
                    'closes_at' => $slot->range->end->value,
                ],
            ));
        } catch (QueryException $exception) {
            throw $exception->getCode() === self::EXCLUSION_VIOLATION
                ? TimeSlotOverlapsException::create()
                : $exception;
        }
    }

    /** Find the slot among those of the current barbershop. */
    public function find(TimeSlotId $id): ?TimeSlot
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * List the slots of the current barbershop ordered by day and opening time.
     *
     * @return list<TimeSlot>
     */
    public function all(): array
    {
        return $this->toDomainList($this->ofBarbershop());
    }

    /**
     * List the slots of the current barbershop on one day ordered by opening time.
     *
     * @return list<TimeSlot>
     */
    public function ofDay(DayOfWeek $day): array
    {
        return $this->toDomainList($this->ofBarbershop()->where('day_of_week', $day->value));
    }

    /** Delete the row of the slot from the current barbershop. */
    public function delete(TimeSlot $slot): void
    {
        $this->ofBarbershop()->whereKey($slot->id->value)->delete();
    }

    /**
     * Scope the query to the current barbershop in schedule order; the row-level policy is the second barrier.
     *
     * @return Builder<TimeSlotModel>
     */
    private function ofBarbershop(): Builder
    {
        return TimeSlotModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->orderBy('day_of_week')
            ->orderBy('opens_at')
            ->orderBy('id');
    }

    /**
     * Run the query and rebuild every slot.
     *
     * @param  Builder<TimeSlotModel>  $query
     * @return list<TimeSlot>
     */
    private function toDomainList(Builder $query): array
    {
        return array_values($query->get()->map($this->toDomain(...))->all());
    }

    /** Rebuild the slot from its stored state, dropping the seconds PostgreSQL returns. */
    private function toDomain(TimeSlotModel $model): TimeSlot
    {
        return TimeSlot::reconstitute(
            new TimeSlotId($model->id),
            new DayOfWeek($model->day_of_week),
            TimeRange::between(
                new TimeOfDay(substr($model->opens_at, 0, 5)),
                new TimeOfDay(substr($model->closes_at, 0, 5)),
            ),
        );
    }
}
