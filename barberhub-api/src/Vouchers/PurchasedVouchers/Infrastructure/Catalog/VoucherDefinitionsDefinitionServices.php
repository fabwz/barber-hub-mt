<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog;

use Src\Vouchers\PurchasedVouchers\Application\Contracts\DefinitionServices;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\CoveredService;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\CoveredService as DefinitionCoveredService;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\ListCoveredServices;

final readonly class VoucherDefinitionsDefinitionServices implements DefinitionServices
{
    /** Receive the VoucherDefinitions use case that names the services the definitions cover. */
    public function __construct(private ListCoveredServices $listCovered) {}

    /**
     * Read the covered services through VoucherDefinitions.
     *
     * @param  list<string>  $definitionIds
     * @return array<string, list<CoveredService>>
     */
    public function of(array $definitionIds): array
    {
        return array_map(
            fn (array $services): array => array_map(fn (DefinitionCoveredService $service): CoveredService => new CoveredService($service->id, $service->name), $services),
            $definitionIds === [] ? [] : $this->listCovered->execute($definitionIds),
        );
    }
}
