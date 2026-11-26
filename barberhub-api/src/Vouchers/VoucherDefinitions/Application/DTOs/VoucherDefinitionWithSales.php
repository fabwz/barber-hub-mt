<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\DTOs;

use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

/** A voucher definition with whether a customer has already bought it, so the owner knows which terms are locked (RF-26). */
final readonly class VoucherDefinitionWithSales
{
    /** Pair the definition with its sales flag. */
    public function __construct(public VoucherDefinition $definition, public bool $hasSales) {}
}
