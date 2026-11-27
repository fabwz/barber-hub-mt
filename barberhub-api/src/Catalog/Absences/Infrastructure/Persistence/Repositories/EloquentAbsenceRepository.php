<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Infrastructure\Persistence\Repositories;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Src\Catalog\Absences\Domain\Contracts\AbsenceRepository;
use Src\Catalog\Absences\Domain\Entities\Absence;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceId;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceReason;
use Src\Catalog\Absences\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Absences\Infrastructure\Persistence\Models\AbsenceModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class EloquentAbsenceRepository implements AbsenceRepository
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new absence. */
    public function nextId(): AbsenceId
    {
        return new AbsenceId((string) Str::uuid7());
    }

    /** Insert the absence in the current barbershop; the foreign key keeps the collaborator in the same barbershop. */
    public function save(Absence $absence): void
    {
        AbsenceModel::query()->updateOrCreate(
            ['id' => $absence->id->value, 'barbershop_id' => $this->context->current()->value],
            [
                'collaborator_id' => $absence->collaboratorId->value,
                'starts_at' => $absence->interval->start,
                'ends_at' => $absence->interval->end,
                'reason' => $absence->reason?->value,
            ],
        );
    }

    /** Find the absence among those of the current barbershop. */
    public function find(AbsenceId $id): ?Absence
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /** Delete the row of the absence from the current barbershop. */
    public function delete(Absence $absence): void
    {
        $this->ofBarbershop()->whereKey($absence->id->value)->delete();
    }

    /**
     * List the absences of the current barbershop that have not ended at the instant, the earliest first.
     *
     * @return list<Absence>
     */
    public function current(DateTimeImmutable $now): array
    {
        return array_values(
            $this->ofBarbershop()->where('ends_at', '>', $now)->orderBy('starts_at')->orderBy('id')->get()->map($this->toDomain(...))->all(),
        );
    }

    /**
     * Read the intervals of the absences of some collaborators that overlap a window.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window): array
    {
        $rows = $this->ofBarbershop()
            ->whereIn('collaborator_id', $collaboratorIds)
            ->where('starts_at', '<', $window->end)
            ->where('ends_at', '>', $window->start)
            ->orderBy('starts_at')
            ->get(['collaborator_id', 'starts_at', 'ends_at']);

        $intervals = [];

        foreach ($rows as $row) {
            $intervals[$row->collaborator_id][] = TimeInterval::between(
                DateTimeImmutable::createFromInterface($row->starts_at),
                DateTimeImmutable::createFromInterface($row->ends_at),
            );
        }

        return $intervals;
    }

    /**
     * Scope the query to the current barbershop; the row-level policy is the second barrier.
     *
     * @return Builder<AbsenceModel>
     */
    private function ofBarbershop(): Builder
    {
        return AbsenceModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** Rebuild the entity from its stored state. */
    private function toDomain(AbsenceModel $model): Absence
    {
        return Absence::reconstitute(
            new AbsenceId($model->id),
            new CollaboratorId($model->collaborator_id),
            TimeInterval::between(
                DateTimeImmutable::createFromInterface($model->starts_at),
                DateTimeImmutable::createFromInterface($model->ends_at),
            ),
            $model->reason === null ? null : new AbsenceReason($model->reason),
        );
    }
}
