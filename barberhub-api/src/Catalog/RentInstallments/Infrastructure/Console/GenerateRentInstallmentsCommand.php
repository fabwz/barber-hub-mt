<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Catalog\RentInstallments\Application\UseCases\GenerateRentInstallments;
use Src\Shared\Domain\Contracts\Clock;

/**
 * The daily task of the rent (RF-57): creates the installment that follows when one is over and completes the first installment of a
 * barbershop that has none. It is idempotent and runs with no session. In production it only runs when the scheduler exists (#117).
 */
final class GenerateRentInstallmentsCommand extends Command
{
    protected $signature = 'rent:generate-installments';

    protected $description = 'Create the monthly rent installments that are missing (idempotent)';

    /** Run the generation at the current instant and report what it did. */
    public function handle(GenerateRentInstallments $generate, Clock $clock): int
    {
        $result = $generate->execute($clock->now());

        $this->components->info("Rent installments created: {$result->created}");

        foreach ($result->failedBarbershopIds as $barbershopId) {
            $this->components->error("The installments of barbershop {$barbershopId} could not be generated.");
        }

        return $result->failedBarbershopIds === [] ? self::SUCCESS : self::FAILURE;
    }
}
