<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Domain\Contracts;

use Src\Reservations\AssistedClients\Domain\Entities\AssistedClient;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientId;

interface AssistedClientRepository
{
    /** Generate the identity for a new assisted client. */
    public function nextId(): AssistedClientId;

    /** Persist a new assisted client in the current barbershop. */
    public function save(AssistedClient $client): void;

    /**
     * Find the assisted clients of the current barbershop with those identifiers; the ones that do not exist there are left out.
     *
     * @param  list<AssistedClientId>  $ids
     * @return list<AssistedClient>
     */
    public function findMany(array $ids): array;

    /**
     * Search the assisted clients of the current barbershop whose name or phone contains the term, ordered by name; with no term, the
     * first ones. At most `$limit` are read.
     *
     * @return list<AssistedClient>
     */
    public function search(?string $term, int $limit): array;
}
