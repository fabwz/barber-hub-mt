<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Persistence\Repositories;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;
use Src\Catalog\Barbershops\Domain\Exceptions\PublicLinkTakenException;
use Src\Catalog\Barbershops\Domain\ValueObjects\BarbershopName;
use Src\Catalog\Barbershops\Domain\ValueObjects\City;
use Src\Catalog\Barbershops\Domain\ValueObjects\PublicLink;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class EloquentBarbershopRepository implements BarbershopRepository
{
    /** Name of the unique key that keeps links apart; it tells a clash of links from any other duplicate. */
    private const string LINK_KEY = 'barbershops_public_link_unique';

    /** Generate a time-ordered UUID for a new barbershop. */
    public function nextId(): BarbershopId
    {
        return new BarbershopId((string) Str::uuid7());
    }

    /**
     * Insert the barbershop with its link or update its data, inside a savepoint so a clash of links leaves the
     * surrounding transaction usable. The link is written once: an update never touches it (RF-02).
     */
    public function save(Barbershop $barbershop): void
    {
        try {
            DB::transaction(function () use ($barbershop): void {
                $data = [
                    'name' => $barbershop->name->value,
                    'city' => $barbershop->city->value,
                    'phone' => $barbershop->phone->value,
                ];
                $model = BarbershopModel::query()->find($barbershop->id->value);

                if ($model === null) {
                    BarbershopModel::query()->create([
                        'id' => $barbershop->id->value,
                        ...$data,
                        'public_link' => $barbershop->link->value,
                    ]);

                    return;
                }

                $model->update($data);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), self::LINK_KEY)) {
                throw $exception;
            }

            throw PublicLinkTakenException::create();
        }
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
            new PublicLink($model->public_link),
        );
    }
}
