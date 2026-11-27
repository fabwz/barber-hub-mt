<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Application\UseCases;

use Src\Reservations\AssistedClients\Domain\Contracts\AssistedClientRepository;
use Src\Reservations\AssistedClients\Domain\Entities\AssistedClient;
use Src\Reservations\AssistedClients\Domain\ValueObjects\AssistedClientId;

final readonly class ListAssistedClients
{
    /** Receive the repository port. */
    public function __construct(private AssistedClientRepository $clients) {}

    /**
     * Read the assisted clients of the current barbershop with those identifiers, in one read; the ones it does not have are left out. The
     * agenda uses it to show who each assisted reservation is for, and the notices to know where to write to them.
     *
     * @param  list<string>  $ids
     * @return list<AssistedClient>
     */
    public function execute(array $ids): array
    {
        return $ids === [] ? [] : $this->clients->findMany(array_map(fn (string $id): AssistedClientId => new AssistedClientId($id), $ids));
    }
}
