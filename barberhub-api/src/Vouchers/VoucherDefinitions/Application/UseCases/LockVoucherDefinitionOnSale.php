<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionNotFoundException;

final readonly class LockVoucherDefinitionOnSale
{
    /** Receive the voucher definition repository port. */
    public function __construct(private VoucherDefinitionRepository $definitions) {}

    /**
     * Read a voucher a customer is buying and lock it until the end of the transaction (RF-27). It must be on sale in the current
     * barbershop: one that does not exist, belongs to another barbershop or was withdrawn reads like a missing resource, as an
     * inactive service does when booking (UP-06, MT-03). The lock makes an edit of its sessions, validity or services wait for the
     * purchase that is copying them, and the purchase wait for an edit that is changing them, so the purchased voucher never keeps
     * terms that were never on sale (RF-26).
     *
     * @throws VoucherDefinitionNotFoundException
     */
    public function execute(string $definitionId): VoucherDefinition
    {
        $definition = $this->definitions->findForUpdate(new VoucherDefinitionId($definitionId)) ?? throw VoucherDefinitionNotFoundException::create();

        return $definition->isActive ? $definition : throw VoucherDefinitionNotFoundException::create();
    }
}
