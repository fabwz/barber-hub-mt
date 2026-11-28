<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Console;

use Illuminate\Console\Command;
use Src\Catalog\RentInstallments\Application\UseCases\BlockOverdueBarbershops;
use Src\Shared\Domain\Contracts\Clock;

/**
 * The daily blocking for unpaid rent (RF-58): blocks the barbershops with an overdue unpaid installment, the day after it fell due. It is
 * idempotent and runs with no session. In production it only runs when the scheduler exists (#117).
 */
final class BlockOverdueBarbershopsCommand extends Command
{
    protected $signature = 'rent:block-overdue-barbershops';

    protected $description = 'Block the barbershops with an overdue unpaid rent installment (idempotent)';

    /** Run the blocking at the current instant and report what it did. */
    public function handle(BlockOverdueBarbershops $block, Clock $clock): int
    {
        $result = $block->execute($clock->now());

        $this->components->info("Barbershops blocked: {$result->blocked}");

        foreach ($result->failedBarbershopIds as $barbershopId) {
            $this->components->error("Barbershop {$barbershopId} could not be checked for overdue rent.");
        }

        return $result->failedBarbershopIds === [] ? self::SUCCESS : self::FAILURE;
    }
}
