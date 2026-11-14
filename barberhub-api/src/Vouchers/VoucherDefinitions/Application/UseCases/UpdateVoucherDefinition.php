<?php

declare(strict_types=1);

namespace Src\Vouchers\VoucherDefinitions\Application\UseCases;

use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Vouchers\Shared\Domain\ValueObjects\SessionCount;
use Src\Vouchers\Shared\Domain\ValueObjects\ValidityDays;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherDefinitionId;
use Src\Vouchers\Shared\Domain\ValueObjects\VoucherName;
use Src\Vouchers\VoucherDefinitions\Application\DTOs\UpdateVoucherDefinitionCommand;
use Src\Vouchers\VoucherDefinitions\Application\ResolveCoveredServices;
use Src\Vouchers\VoucherDefinitions\Domain\Contracts\VoucherDefinitionRepository;
use Src\Vouchers\VoucherDefinitions\Domain\Entities\VoucherDefinition;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\UnknownCoveredServiceException;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionHasSalesException;
use Src\Vouchers\VoucherDefinitions\Domain\Exceptions\VoucherDefinitionNotFoundException;
use Src\Vouchers\VoucherDefinitions\Domain\ValueObjects\CoveredServices;

final readonly class UpdateVoucherDefinition
{
    /** Receive the ports of the definitions and of the clock, and the check of the services. */
    public function __construct(
        private VoucherDefinitionRepository $definitions,
        private ResolveCoveredServices $coveredServices,
        private Clock $clock,
    ) {}

    /**
     * Apply the requested changes to a definition of the current barbershop, including withdrawing it from sale or putting it back
     * (RF-26). Once a customer has bought it, its sessions, validity and services stay as they were sold: asking for another value
     * is a conflict, and asking for the same one changes nothing. Name, price and the sale flag are free in any case, and the
     * vouchers already bought keep their own copy of what they paid (BO-01).
     *
     * @throws VoucherDefinitionNotFoundException
     * @throws VoucherDefinitionHasSalesException
     * @throws UnknownCoveredServiceException
     */
    public function execute(UpdateVoucherDefinitionCommand $command): VoucherDefinition
    {
        $id = new VoucherDefinitionId($command->definitionId);
        $definition = $this->definitions->find($id) ?? throw VoucherDefinitionNotFoundException::create();
        $now = $this->clock->now();

        $sessions = $command->sessionsCount === null ? $definition->sessionsCount : SessionCount::of($command->sessionsCount);
        $validity = $command->validityDays === null ? $definition->validityDays : ValidityDays::of($command->validityDays);
        $services = $command->serviceIds === null ? $definition->services : $this->coveredServices->execute($command->serviceIds);

        if ($this->changesTheTerms($definition, $sessions, $validity, $services) && $this->definitions->hasPurchases($id)) {
            throw VoucherDefinitionHasSalesException::create();
        }

        $definition->revise(
            $command->name === null ? $definition->name : VoucherName::of($command->name),
            $command->price === null ? $definition->price : Money::of($command->price),
            $now,
        );
        $definition->reconfigure($sessions, $validity, $services, $now);

        match ($command->active) {
            true => $definition->reactivate($now),
            false => $definition->withdraw($now),
            null => null,
        };

        $this->definitions->save($definition);

        return $definition;
    }

    /** Tell whether the request moves the sessions, the validity or the covered services away from the current ones. */
    private function changesTheTerms(VoucherDefinition $definition, SessionCount $sessions, ValidityDays $validity, CoveredServices $services): bool
    {
        return ! $definition->sessionsCount->equals($sessions)
            || ! $definition->validityDays->equals($validity)
            || ! $definition->services->sameAs($services);
    }
}
