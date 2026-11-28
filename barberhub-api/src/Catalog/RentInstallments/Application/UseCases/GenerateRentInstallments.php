<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Application\Contracts\RegisteredBarbershops;
use Src\Catalog\RentInstallments\Application\DTOs\RentGenerationResult;
use Throwable;

final readonly class GenerateRentInstallments
{
    /** Receive the barbershops and the use case that completes one of them. */
    public function __construct(
        private RegisteredBarbershops $barbershops,
        private EnsureRentInstallments $ensure,
    ) {}

    /**
     * Create the installments every barbershop is missing at this instant: the daily task (RF-57). Each barbershop is processed in its own
     * transaction, so one that fails does not stop the others and is reported in the result.
     */
    public function execute(DateTimeImmutable $now): RentGenerationResult
    {
        $created = 0;
        $failed = [];

        foreach ($this->barbershops->ids() as $barbershopId) {
            try {
                $created += $this->ensure->execute($barbershopId, $now);
            } catch (Throwable) {
                $failed[] = $barbershopId;
            }
        }

        return new RentGenerationResult($created, $failed);
    }
}
