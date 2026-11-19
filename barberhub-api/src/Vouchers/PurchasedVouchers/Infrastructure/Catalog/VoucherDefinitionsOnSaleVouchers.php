<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog;

use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\OnSaleVouchers;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\CoveredService;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherForSale;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherOnSale;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\CoveredService as DefinitionCoveredService;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\VoucherDefinitionListing;
use Src\Vouchers\VoucherDefinitions\Application\UseCases\ListVouchersOnSale;

final readonly class VoucherDefinitionsOnSaleVouchers implements OnSaleVouchers
{
    /** Receive the VoucherDefinitions use case that lists the vouchers on sale. */
    public function __construct(private ListVouchersOnSale $listOnSale) {}

    /**
     * Read the vouchers on sale through VoucherDefinitions.
     *
     * @return Page<VoucherForSale>
     */
    public function page(PageRequest $page): Page
    {
        return $this->listOnSale->execute($page)->map(fn (VoucherDefinitionListing $listing): VoucherForSale => new VoucherForSale(
            new VoucherOnSale($listing->definition->id, $listing->definition->name, $listing->definition->price, $listing->definition->sessionsCount, $listing->definition->validityDays),
            array_map(fn (DefinitionCoveredService $service): CoveredService => new CoveredService($service->id, $service->name), $listing->services),
        ));
    }
}
