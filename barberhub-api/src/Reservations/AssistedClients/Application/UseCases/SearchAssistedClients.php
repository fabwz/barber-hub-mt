<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Application\UseCases;

use Src\Reservations\AssistedClients\Domain\Contracts\AssistedClientRepository;
use Src\Reservations\AssistedClients\Domain\Entities\AssistedClient;

final readonly class SearchAssistedClients
{
    /** Most results a search brings: the owner narrows it by typing more of the name or the phone. */
    public const int LIMIT = 10;

    /** Receive the repository port. */
    public function __construct(private AssistedClientRepository $clients) {}

    /**
     * Search the assisted clients of the current barbershop by a part of their name or phone, ordered by name (RF-12). A client of another
     * barbershop never shows up (CL-06).
     *
     * @return list<AssistedClient>
     */
    public function execute(?string $term): array
    {
        $term = $term === null ? null : trim($term);

        return $this->clients->search($term === '' ? null : $term, self::LIMIT);
    }
}
