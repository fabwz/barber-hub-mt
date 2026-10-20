<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Services;

use Src\Catalog\Collaborators\Application\Contracts\BarbershopServices;
use Src\Catalog\Services\Application\UseCases\ListServices;
use Src\Catalog\Services\Domain\Entities\Service;

final readonly class ServicesBarbershopServices implements BarbershopServices
{
    /** Receive the Services use case that lists the services of the barbershop. */
    public function __construct(private ListServices $listServices) {}

    /**
     * Pick, among the given identifiers, the services of the current barbershop through the Services module.
     *
     * @param  list<string>  $serviceIds
     * @return list<string>
     */
    public function existingAmong(array $serviceIds): array
    {
        $known = array_map(fn (Service $service): string => $service->id->value, $this->listServices->execute());

        return array_values(array_intersect($serviceIds, $known));
    }
}
