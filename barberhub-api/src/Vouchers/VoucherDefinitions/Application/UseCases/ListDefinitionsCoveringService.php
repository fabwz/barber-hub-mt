<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\ServiceId;

final readonly class ListDefinitionsCoveringService
{
    /** Receive the voucher definition repository port. */
    public function __construct(private VoucherDefinitionRepository $definitions) {}

    /**
     * The identifiers of the definitions of the current barbershop that cover the service (BO-06). It includes the withdrawn ones: a
     * voucher already bought keeps what it covers when the barbershop stops selling it (RF-26).
     *
     * @return list<string>
     */
    public function execute(string $serviceId): array
    {
        return $this->definitions->idsCoveringService(new ServiceId($serviceId));
    }
}
