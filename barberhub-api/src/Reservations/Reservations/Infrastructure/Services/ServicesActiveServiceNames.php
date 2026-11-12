<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Services;

use Src\Catalog\Services\Application\DTOs\PublicService;
use Src\Catalog\Services\Application\UseCases\ListPublicServices;
use Src\Reservations\Reservations\Application\Contracts\ActiveServiceNames;

final readonly class ServicesActiveServiceNames implements ActiveServiceNames
{
    /** Receive the Services use case that lists what a customer may book. */
    public function __construct(private ListPublicServices $listServices) {}

    /**
     * Read the active services through the Services module and keep only their names.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_map(fn (PublicService $service): string => $service->name, $this->listServices->execute());
    }
}
