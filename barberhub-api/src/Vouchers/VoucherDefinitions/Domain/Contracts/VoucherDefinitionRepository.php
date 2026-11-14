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

    /**
     * Find a definition among those of the current barbershop and lock its row until the transaction ends. The sale of a voucher and
     * the edit of its terms both take this lock first, so a purchase never copies terms that an edit is changing (RF-26, RF-27).
     */
    public function findForUpdate(VoucherDefinitionId $id): ?VoucherDefinition;

    /**
     * List every definition of the current barbershop, on sale or withdrawn, ordered by name.
     *
     * @return list<VoucherDefinition>
     */
    public function all(): array;

    /** Tell whether a customer of the current barbershop has bought the voucher, paid or not (RF-26). */
    public function hasPurchases(VoucherDefinitionId $id): bool;
}
