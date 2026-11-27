<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Infrastructure\Persistence\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Src\Reservations\AssistedClients\Domain\Contracts\AssistedClientRepository;
use Src\Reservations\AssistedClients\Domain\Entities\AssistedClient;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientEmail;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientId;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientName;
use Src\Reservations\AssistedClients\Infrastructure\Persistence\Models\AssistedClientModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class EloquentAssistedClientRepository implements AssistedClientRepository
{
    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /** Generate a time-ordered UUID for a new assisted client. */
    public function nextId(): AssistedClientId
    {
        return new AssistedClientId((string) Str::uuid7());
    }

    /** Insert the assisted client in the current barbershop. */
    public function save(AssistedClient $client): void
    {
        AssistedClientModel::query()->updateOrCreate(
            ['id' => $client->id->value, 'barbershop_id' => $this->context->current()->value],
            [
                'name' => $client->name->value,
                'phone' => $client->phone->value,
                'email' => $client->email?->value,
            ],
        );
    }

    /**
     * Read the assisted clients with those identifiers among those of the current barbershop.
     *
     * @param  list<AssistedClientId>  $ids
     * @return list<AssistedClient>
     */
    public function findMany(array $ids): array
    {
        return array_values(
            $this->ofBarbershop()->whereIn('id', array_map(fn (AssistedClientId $id): string => $id->value, $ids))->get()->map($this->toDomain(...))->all(),
        );
    }

    /**
     * Search by a part of the name or the phone, without caring for case; the wildcards of LIKE the person typed are taken literally.
     *
     * @return list<AssistedClient>
     */
    public function search(?string $term, int $limit): array
    {
        $query = $this->ofBarbershop();

        if ($term !== null) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(fn (Builder $inner): Builder => $inner->where('name', 'ilike', $like)->orWhere('phone', 'ilike', $like));
        }

        return array_values($query->orderBy('name')->orderBy('id')->limit($limit)->get()->map($this->toDomain(...))->all());
    }

    /**
     * Scope the query to the current barbershop; the row-level policy is the second barrier.
     *
     * @return Builder<AssistedClientModel>
     */
    private function ofBarbershop(): Builder
    {
        return AssistedClientModel::query()->where('barbershop_id', $this->context->current()->value);
    }

    /** Rebuild the entity from its stored state. */
    private function toDomain(AssistedClientModel $model): AssistedClient
    {
        return AssistedClient::reconstitute(
            new AssistedClientId($model->id),
            new AssistedClientName($model->name),
            new Phone($model->phone),
            $model->email === null ? null : new AssistedClientEmail($model->email),
        );
    }
}
