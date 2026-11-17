<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog;

use Src\Vouchers\PurchasedVouchers\Application\Contracts\CoveringDefinitions;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\ListDefinitionsCoveringService;

final readonly class VoucherDefinitionsCoveringDefinitions implements CoveringDefinitions
{
    /** Receive the VoucherDefinitions use case that tells which definitions cover a service. */
    public function __construct(private ListDefinitionsCoveringService $listCovering) {}

    /**
     * Read the definitions that cover the service through VoucherDefinitions.
     *
     * @return list<string>
     */
    public function idsCovering(string $serviceId): array
    {
        return $this->listCovering->execute($serviceId);
    }
}
