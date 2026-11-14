<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

final readonly class ListVoucherDefinitions
{
    /** Receive the voucher definition repository port. */
    public function __construct(private VoucherDefinitionRepository $definitions) {}

    /**
     * List every voucher definition of the current barbershop, on sale or withdrawn, for its owner.
     *
     * @return list<VoucherDefinition>
     */
    public function execute(): array
    {
        return $this->definitions->all();
    }
}
