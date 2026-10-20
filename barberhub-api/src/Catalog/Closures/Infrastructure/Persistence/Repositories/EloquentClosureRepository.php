<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Closures\Domain\Contracts\ClosureRepository;
use Src\Catalog\Closures\Domain\Entities\Closure;
use Src\Catalog\Closures\Domain\Exceptions\ClosureAlreadyExistsException;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureId;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureReason;
use Src\Catalog\Closures\Infrastructure\Persistence\Models\ClosureModel;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class EloquentClosureRepository implements ClosureRepository
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new closure. */
    public function nextId(): ClosureId
    {
        return new ClosureId((string) Str::uuid7());
    }

    /** Insert the closure inside a savepoint and translate a second closure on the same date (ADR 0009). */
    public function save(Closure $closure): void
    {
        try {
            DB::transaction(fn () => ClosureModel::query()->updateOrCreate(
                ['id' => $closure->id->value, 'barbershop_id' => $this->context->current()->value],
                [
                    'closed_on' => $closure->date->value,
                    'reason' => $closure->reason?->value,
                ],
            ));
        } catch (UniqueConstraintViolationException) {
            throw ClosureAlreadyExistsException::create();
        }
    }

    /** Find the closure among those of the current barbershop. */
    public function find(ClosureId $id): ?Closure
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Find the closure of the current barbershop on a date. */
    public function onDate(ClosureDate $date): ?Closure
    {
        $model = $this->ofBarbershop()->where('closed_on', $date->value)->first();

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * List the closures of the current barbershop from a date onward, ordered by date.
     *
     * @return list<Closure>
     */
    public function from(ClosureDate $date): array
    {
        return array_values(
            $this->ofBarbershop()->where('closed_on', '>=', $date->value)->orderBy('closed_on')->get()->map($this->toDomain(...))->all(),
        );
    }

    /** Delete the row of the closure from the current barbershop. */
    public function delete(Closure $closure): void
    {
        $this->ofBarbershop()->whereKey($closure->id->value)->delete();
    }

    /**
     * Scope the query to the current barbershop; the row-level policy is the second barrier.
     *
     * @return Builder<ClosureModel>
     */
    private function ofBarbershop(): Builder
    {
        return ClosureModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** Rebuild the entity from its stored state. */
    private function toDomain(ClosureModel $model): Closure
    {
        return Closure::reconstitute(
            new ClosureId($model->id),
            ClosureDate::fromMoment($model->closed_on),
            $model->reason === null ? null : new ClosureReason($model->reason),
        );
    }
}
