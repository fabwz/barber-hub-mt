<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\BreaksOverlapException;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorAlreadyExistsException;
use Src\Catalog\Collaborators\Domain\Exceptions\ShiftsOverlapException;
use Src\Catalog\Collaborators\Domain\ValueObjects\BreakPeriod;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Collaborators\Domain\ValueObjects\Shift;
use Src\Catalog\Collaborators\Domain\ValueObjects\WorkSchedule;
use Src\Catalog\Collaborators\Infrastructure\Persistence\Models\CollaboratorModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Src\Shared\Domain\ValueObjects\UserId;
use stdClass;

final readonly class EloquentCollaboratorRepository implements CollaboratorRepository
{
    private const string EXCLUSION_VIOLATION = '23P01';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new collaborator. */
    public function nextId(): CollaboratorId
    {
        return new CollaboratorId((string) Str::uuid7());
    }

    /**
     * Write the profile and replace its services, shifts and breaks inside a savepoint, so a rejected schedule leaves the
     * stored one as it was. A second profile of the same account and an overlap the use case missed are translated (ADR 0009).
     */
    public function save(Collaborator $collaborator): void
    {
        $barbershopId = $this->context->current()->value;

        try {
            DB::transaction(function () use ($collaborator, $barbershopId): void {
                CollaboratorModel::query()->updateOrCreate(
                    ['id' => $collaborator->id->value, 'barbershop_id' => $barbershopId],
                    ['user_id' => $collaborator->userId->value, 'is_active' => $collaborator->active],
                );

                $this->replaceChildren('collaborator_services', $collaborator, $barbershopId, array_map(
                    fn (OfferedServiceId $service): array => ['service_id' => $service->value],
                    $collaborator->services,
                ));
                $this->replaceChildren('collaborator_shifts', $collaborator, $barbershopId, array_map(
                    fn (Shift $shift): array => $this->timesOf($shift->day, $shift->range),
                    $collaborator->schedule->shifts,
                ));
                $this->replaceChildren('collaborator_breaks', $collaborator, $barbershopId, array_map(
                    fn (BreakPeriod $break): array => $this->timesOf($break->day, $break->range),
                    $collaborator->schedule->breaks,
                ));
            });
        } catch (UniqueConstraintViolationException) {
            throw CollaboratorAlreadyExistsException::create();
        } catch (QueryException $exception) {
            if ($exception->getCode() !== self::EXCLUSION_VIOLATION) {
                throw $exception;
            }

            // The database does not know which entry of the request collided, so the error points at the first one.
            throw str_contains($exception->getMessage(), 'collaborator_breaks') ? BreaksOverlapException::at(0) : ShiftsOverlapException::at(0);
        }
    }

    /** Find the collaborator among those of the current barbershop. */
    public function find(CollaboratorId $id): ?Collaborator
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->hydrate(new Collection([$model]))[0];
    }

    /** Find the profile of an account in the current barbershop. */
    public function findByUser(UserId $userId): ?Collaborator
    {
        $model = $this->ofBarbershop()->where('user_id', $userId->value)->first();

        return $model === null ? null : $this->hydrate(new Collection([$model]))[0];
    }

    /**
     * List the collaborators of the current barbershop from the oldest to the newest.
     *
     * @return list<Collaborator>
     */
    public function all(): array
    {
        return $this->hydrate($this->ofBarbershop()->get());
    }

    /**
     * List the active collaborators of the current barbershop who offer the service, from the oldest to the newest.
     *
     * @return list<Collaborator>
     */
    public function activeOffering(OfferedServiceId $service): array
    {
        $query = $this->ofBarbershop()
            ->where('is_active', true)
            ->whereIn('id', DB::table('collaborator_services')
                ->select('collaborator_id')
                ->where('barbershop_id', $this->context->current()->value)
                ->where('service_id', $service->value));

        return $this->hydrate($query->get());
    }

    /**
     * Scope the query to the current barbershop in order of seniority; the row-level policy is the second barrier.
     *
     * @return Builder<CollaboratorModel>
     */
    private function ofBarbershop(): Builder
    {
        return CollaboratorModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * Delete the rows a collaborator has in a child table and insert the new ones.
     *
     * @param  list<array<string, mixed>>  $rows  Columns of each row without the barbershop and the collaborator.
     */
    private function replaceChildren(string $table, Collaborator $collaborator, string $barbershopId, array $rows): void
    {
        DB::table($table)->where('barbershop_id', $barbershopId)->where('collaborator_id', $collaborator->id->value)->delete();

        if ($rows !== []) {
            DB::table($table)->insert(array_map(
                fn (array $row): array => [...$row, 'barbershop_id' => $barbershopId, 'collaborator_id' => $collaborator->id->value],
                $rows,
            ));
        }
    }

    /**
     * Columns of a day and a range of times.
     *
     * @return array{day_of_week: int, starts_at: string, ends_at: string}
     */
    private function timesOf(DayOfWeek $day, TimeRange $range): array
    {
        return ['day_of_week' => $day->value, 'starts_at' => $range->start->value, 'ends_at' => $range->end->value];
    }

    /**
     * Rebuild the collaborators with three queries for all their children, instead of three for each one.
     *
     * @param  Collection<int, CollaboratorModel>  $models
     * @return list<Collaborator>
     */
    private function hydrate(Collection $models): array
    {
        if ($models->isEmpty()) {
            return [];
        }

        $ids = array_values(array_map(fn (CollaboratorModel $model): string => $model->id, $models->all()));
        $barbershopId = $this->context->current()->value;
        $services = $this->childRows('collaborator_services', $ids, $barbershopId)->orderBy('service_id')->get()->groupBy('collaborator_id');
        $shifts = $this->childRows('collaborator_shifts', $ids, $barbershopId)->orderBy('day_of_week')->orderBy('starts_at')->get()->groupBy('collaborator_id');
        $breaks = $this->childRows('collaborator_breaks', $ids, $barbershopId)->orderBy('day_of_week')->orderBy('starts_at')->get()->groupBy('collaborator_id');

        return array_values($models->map(fn (CollaboratorModel $model): Collaborator => Collaborator::reconstitute(
            new CollaboratorId($model->id),
            new UserId($model->user_id),
            $model->is_active,
            array_values(($services[$model->id] ?? collect())->map(fn (stdClass $row): OfferedServiceId => new OfferedServiceId($row->service_id))->all()),
            WorkSchedule::reconstitute(
                array_values(($shifts[$model->id] ?? collect())->map(fn (stdClass $row): Shift => new Shift(...$this->dayAndRange($row)))->all()),
                array_values(($breaks[$model->id] ?? collect())->map(fn (stdClass $row): BreakPeriod => new BreakPeriod(...$this->dayAndRange($row)))->all()),
            ),
        ))->all());
    }

    /**
     * The rows of a child table that belong to some collaborators of the current barbershop.
     *
     * @param  list<string>  $collaboratorIds
     */
    private function childRows(string $table, array $collaboratorIds, string $barbershopId): QueryBuilder
    {
        return DB::table($table)->where('barbershop_id', $barbershopId)->whereIn('collaborator_id', $collaboratorIds);
    }

    /**
     * Rebuild the day and the range of a stored row, dropping the seconds PostgreSQL returns.
     *
     * @return array{day: DayOfWeek, range: TimeRange}
     */
    private function dayAndRange(stdClass $row): array
    {
        return [
            'day' => new DayOfWeek((int) $row->day_of_week),
            'range' => TimeRange::between(new TimeOfDay(substr((string) $row->starts_at, 0, 5)), new TimeOfDay(substr((string) $row->ends_at, 0, 5))),
        ];
    }
}
