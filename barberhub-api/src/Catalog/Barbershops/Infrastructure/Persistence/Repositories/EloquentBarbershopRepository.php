<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Persistence\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class EloquentBarbershopRepository implements BarbershopRepository
{
    /** Generate a time-ordered UUID for a new barbershop. */
    public function nextId(): BarbershopId
    {
        return new BarbershopId((string) Str::uuid7());
    }

    /** Insert or update the barbershop inside a savepoint. */
    public function save(Barbershop $barbershop): void
    {
        DB::transaction(fn () => BarbershopModel::query()->updateOrCreate(
            ['id' => $barbershop->id->value],
            [
                'name' => $barbershop->name->value,
                'city' => $barbershop->city->value,
                'phone' => $barbershop->phone->value,
            ],
        ));
    }

    /** Find the barbershop and rebuild it from its stored state. */
    public function find(BarbershopId $id): ?Barbershop
    {
        $model = BarbershopModel::query()->find($id->value);

        return $model === null ? null : Barbershop::reconstitute(
            new BarbershopId($model->id),
            new BarbershopName($model->name),
            new City($model->city),
            new Phone($model->phone),
        );
    }
}
