<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Vouchers\VoucherDefinitions\Application\DTOs\VoucherDefinitionWithSales;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

final readonly class FlagVoucherDefinitionsWithSales
{
    /** Receive the voucher definition repository port. */
    public function __construct(private VoucherDefinitionRepository $definitions) {}

    /**
     * Tell, for each definition, whether a customer has bought it, with a single aggregate query however many there are (RF-26).
     *
     * @param  list<VoucherDefinition>  $definitions
     * @return list<VoucherDefinitionWithSales>
     */
    public function execute(array $definitions): array
    {
        $sold = array_flip($this->definitions->idsWithPurchases(array_map(static fn (VoucherDefinition $definition) => $definition->id, $definitions)));

        return array_map(
            static fn (VoucherDefinition $definition): VoucherDefinitionWithSales => new VoucherDefinitionWithSales($definition, isset($sold[$definition->id->value])),
            $definitions,
        );
    }
}
