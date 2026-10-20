<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Catalog\Services\Domain\ValueObjects\ServiceDuration;
use Src\Catalog\Services\Domain\ValueObjects\ServiceId;
use Src\Catalog\Services\Domain\ValueObjects\ServiceName;
use Src\Catalog\Services\Domain\ValueObjects\ServicePrice;
use Src\Catalog\Services\Infrastructure\Persistence\Models\ServiceModel;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class EloquentServiceRepository implements ServiceRepository
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new service. */
    public function nextId(): ServiceId
    {
        return new ServiceId((string) Str::uuid7());
    }

    /** Insert the service or update the one of the current barbershop; a row of another barbershop is never matched. */
    public function save(Service $service): void
    {
        ServiceModel::query()->updateOrCreate(
            ['id' => $service->id->value, 'barbershop_id' => $this->context->current()->value],
            [
                'name' => $service->name->value,
                'duration_minutes' => $service->duration->minutes(),
                'price' => $service->price->colones(),
                'is_active' => $service->active,
            ],
        );
    }

    /** Find the service among those of the current barbershop. */
    public function find(ServiceId $id): ?Service
    {
        $model = $this->ofBarbershop()->find($id->value);

        return $model === null ? null : $this->toDomain($model);
    }

    /**
     * List the services of the current barbershop ordered by name.
     *
     * @return list<Service>
     */
    public function all(): array
    {
        return array_values($this->ofBarbershop()->orderBy('name')->orderBy('id')->get()->map($this->toDomain(...))->all());
    }

    /**
     * Scope the query to the current barbershop; the row-level policy is the second barrier.
     *
     * @return Builder<ServiceModel>
     */
    private function ofBarbershop(): Builder
    {
        return ServiceModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** Rebuild the service from its stored state. */
    private function toDomain(ServiceModel $model): Service
    {
        return Service::reconstitute(
            new ServiceId($model->id),
            new ServiceName($model->name),
            new ServiceDuration($model->duration_minutes),
            new ServicePrice($model->price),
            $model->is_active,
        );
    }
}
