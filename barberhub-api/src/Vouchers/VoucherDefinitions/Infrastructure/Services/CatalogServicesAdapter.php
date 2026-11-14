<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Infrastructure\Services;

use Src\Catalog\Services\Application\UseCases\ListServices;
use Src\Catalog\Services\Domain\Entities\Service;
use Src\Vouchers\VoucherDefinitions\Application\Contracts\CatalogServices;

final readonly class CatalogServicesAdapter implements CatalogServices
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
