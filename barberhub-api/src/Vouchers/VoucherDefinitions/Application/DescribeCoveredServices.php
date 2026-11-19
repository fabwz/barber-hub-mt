<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application;

use Src\Vouchers\VoucherDefinitions\Application\Contracts\CatalogServices;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\CoveredService;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

final readonly class DescribeCoveredServices
{
    /** Receive the port of the services of the catalog, which tells their names. */
    public function __construct(private CatalogServices $catalog) {}

    /**
     * Name the services each definition covers, in one read of the catalog for all of them. The names are the ones the services
     * have now, active or not, and the services come ordered by name.
     *
     * @param  list<VoucherDefinition>  $definitions
     * @return array<string, list<CoveredService>> The covered services of each definition, indexed by its identifier.
     */
    public function execute(array $definitions): array
    {
        $ids = [];

        foreach ($definitions as $definition) {
            foreach ($definition->services->ids as $service) {
                $ids[$service->value] = $service->value;
            }
        }

        $names = $this->catalog->namesOf(array_values($ids));
        $described = [];

        foreach ($definitions as $definition) {
            $services = array_map(
                fn (ServiceId $service): CoveredService => new CoveredService($service->value, $names[$service->value] ?? ''),
                $definition->services->ids,
            );
            usort($services, fn (CoveredService $a, CoveredService $b): int => [$a->name, $a->id] <=> [$b->name, $b->id]);
            $described[$definition->id->value] = $services;
        }

        return $described;
    }
}
