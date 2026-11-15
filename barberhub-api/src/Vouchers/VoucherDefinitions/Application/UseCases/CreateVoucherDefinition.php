<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\Shared\Domain\Exceptions\VoucherSalesDisabledException;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Application\Contracts\VoucherSales;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\CreateVoucherDefinitionCommand;
use Src\Vouchers\VoucherDefinitions\Application\ResolveCoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\UnknownCoveredServiceException;

final readonly class CreateVoucherDefinition
{
    /** Receive the ports of the definitions, of the current barbershop and of the clock, and the check of the services. */
    public function __construct(
        private VoucherDefinitionRepository $definitions,
        private ResolveCoveredServices $coveredServices,
        private BarbershopContext $context,
        private Clock $clock,
        private VoucherSales $sales,
    ) {}

    /**
     * Define a voucher on sale in the current barbershop; every service it covers must exist in it (BO-01, RF-26). The barbershop
     * must have the vouchers switched on (RF-09); editing, withdrawing and listing the ones it has never ask for it.
     *
     * @throws VoucherSalesDisabledException
     * @throws UnknownCoveredServiceException
     */
    public function execute(CreateVoucherDefinitionCommand $command): VoucherDefinition
    {
        if (! $this->sales->isEnabled()) {
            throw VoucherSalesDisabledException::create();
        }

        $definition = VoucherDefinition::create(
            $this->definitions->nextId(),
            $this->context->current(),
            VoucherName::of($command->name),
            SessionCount::of($command->sessionsCount),
            Money::of($command->price),
            ValidityDays::of($command->validityDays),
            $this->coveredServices->execute($command->serviceIds),
            $this->clock->now(),
        );

        $this->definitions->save($definition);

        return $definition;
    }
}
