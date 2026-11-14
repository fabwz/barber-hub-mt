<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\Contracts;

use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;

interface VoucherDefinitionRepository
{
    /** Generate the identity for a new voucher definition. */
    public function nextId(): VoucherDefinitionId;

    /** Persist a new definition with the services it covers, or the changes of an existing one, among those of the current barbershop. */
    public function save(VoucherDefinition $definition): void;

    /** Find a definition among those of the current barbershop. */
    public function find(VoucherDefinitionId $id): ?VoucherDefinition;
}
