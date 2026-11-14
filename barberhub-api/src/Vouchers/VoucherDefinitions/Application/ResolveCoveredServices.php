<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application;

use Src\Vouchers\VoucherDefinitions\Application\Contracts\CatalogServices;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\UnknownCoveredServiceException;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

final readonly class ResolveCoveredServices
{
    /** Receive the port of the services of the catalog. */
    public function __construct(private CatalogServices $catalog) {}

    /**
     * Turn the identifiers into the covered services, after checking that every one exists in the current barbershop, active or
     * not (BO-01, MT-04). The first one it does not have is rejected by its position.
     *
     * @param  list<string>  $serviceIds
     *
     * @throws UnknownCoveredServiceException
     */
    public function execute(array $serviceIds): CoveredServices
    {
        $existing = $this->catalog->existingAmong($serviceIds);

        foreach ($serviceIds as $index => $serviceId) {
            if (! in_array($serviceId, $existing, true)) {
                throw UnknownCoveredServiceException::at($index);
            }
        }

        return CoveredServices::of(array_map(fn (string $id): ServiceId => new ServiceId($id), $serviceIds));
    }
}
