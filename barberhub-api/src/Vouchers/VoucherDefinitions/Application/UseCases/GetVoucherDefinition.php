<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionNotFoundException;

final readonly class GetVoucherDefinition
{
    /** Receive the voucher definition repository port. */
    public function __construct(private VoucherDefinitionRepository $definitions) {}

    /**
     * Read a voucher definition of the current barbershop, on sale or not.
     *
     * @throws VoucherDefinitionNotFoundException
     */
    public function execute(string $definitionId): VoucherDefinition
    {
        return $this->definitions->find(new VoucherDefinitionId($definitionId)) ?? throw VoucherDefinitionNotFoundException::create();
    }
}
