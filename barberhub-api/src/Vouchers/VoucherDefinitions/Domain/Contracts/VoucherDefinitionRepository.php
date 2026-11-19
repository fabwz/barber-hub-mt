<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Domain\Contracts;

use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

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

    /**
     * One page of the definitions of the current barbershop that are on sale, ordered by name; the withdrawn ones are left out.
     *
     * @return Page<VoucherDefinition>
     */
    public function pageOnSale(PageRequest $page): Page;

    /**
     * The definitions among the given identifiers that belong to the current barbershop, on sale or withdrawn; an identifier that is
     * not found is left out.
     *
     * @param  list<VoucherDefinitionId>  $ids
     * @return list<VoucherDefinition>
     */
    public function findMany(array $ids): array;

    /** Tell whether a customer of the current barbershop has bought the voucher, paid or not (RF-26). */
    public function hasPurchases(VoucherDefinitionId $id): bool;

    /**
     * The identifiers of the definitions of the current barbershop that cover the service, on sale or withdrawn: a withdrawn voucher
     * is no longer sold, but the ones already bought keep what they cover (RF-26, BO-06).
     *
     * @return list<string>
     */
    public function idsCoveringService(ServiceId $service): array;
}
