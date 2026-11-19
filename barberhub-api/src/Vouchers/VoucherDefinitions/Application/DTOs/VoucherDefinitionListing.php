<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\DTOs;

use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

/** A voucher definition with the services it covers, named. */
final readonly class VoucherDefinitionListing
{
    /** @param  list<CoveredService>  $services  Ordered by name. */
    public function __construct(public VoucherDefinition $definition, public array $services) {}
}
