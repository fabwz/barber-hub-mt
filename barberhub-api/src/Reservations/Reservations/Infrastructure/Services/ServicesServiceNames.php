<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Services;

use Src\Catalog\Services\Application\UseCases\ListServices;
use Src\Reservations\Reservations\Application\Contracts\ServiceNames;

final readonly class ServicesServiceNames implements ServiceNames
{
    /** Receive the Services use case that lists every service of the barbershop. */
    public function __construct(private ListServices $listServices) {}

    /**
     * Read the services through the Services module and keep their names.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $names = [];

        foreach ($this->listServices->execute() as $service) {
            $names[$service->id->value] = $service->name->value;
        }

        return $names;
    }
}
