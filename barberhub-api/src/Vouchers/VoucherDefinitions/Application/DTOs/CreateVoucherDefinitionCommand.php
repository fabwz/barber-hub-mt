<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\DTOs;

final readonly class CreateVoucherDefinitionCommand
{
    /**
     * Carry the data needed to define a voucher; the price includes VAT.
     *
     * @param  list<string>  $serviceIds
     */
    public function __construct(
        public string $name,
        public int $sessionsCount,
        public int $price,
        public int $validityDays,
        public array $serviceIds,
    ) {}
}
