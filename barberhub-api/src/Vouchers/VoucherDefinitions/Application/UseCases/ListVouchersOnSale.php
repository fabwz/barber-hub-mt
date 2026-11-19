<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\VoucherDefinitions\Application\DescribeCoveredServices;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\VoucherDefinitionListing;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

final readonly class ListVouchersOnSale
{
    /** Receive the repository and the reader of the names of the covered services. */
    public function __construct(private VoucherDefinitionRepository $definitions, private DescribeCoveredServices $describe) {}

    /**
     * List one page of the vouchers on sale in the current barbershop, by name, each with the services it covers; the withdrawn ones
     * are left out (RF-26, BO-01).
     *
     * @return Page<VoucherDefinitionListing>
     */
    public function execute(PageRequest $page): Page
    {
        $onSale = $this->definitions->pageOnSale($page);
        $services = $this->describe->execute($onSale->items);

        return $onSale->map(fn (VoucherDefinition $definition): VoucherDefinitionListing => new VoucherDefinitionListing($definition, $services[$definition->id->value]));
    }
}
