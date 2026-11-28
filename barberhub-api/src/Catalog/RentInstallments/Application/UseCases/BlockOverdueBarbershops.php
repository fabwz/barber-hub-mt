<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Application\Contracts\RegisteredBarbershops;
use Src\Catalog\RentInstallments\Application\DTOs\RentBlockingResult;
use Throwable;

final readonly class BlockOverdueBarbershops
{
    /** Receive the barbershops and the use case that blocks one of them. */
    public function __construct(
        private RegisteredBarbershops $barbershops,
        private BlockBarbershopForOverdueRent $block,
    ) {}

    /**
     * Block every barbershop with an overdue unpaid installment at this instant: the daily task (RF-58). Each barbershop is processed in its
     * own transaction, so one that fails does not stop the others and is reported in the result.
     */
    public function execute(DateTimeImmutable $now): RentBlockingResult
    {
        $blocked = 0;
        $failed = [];

        foreach ($this->barbershops->ids() as $barbershopId) {
            try {
                if ($this->block->execute($barbershopId, $now)) {
                    $blocked++;
                }
            } catch (Throwable) {
                $failed[] = $barbershopId;
            }
        }

        return new RentBlockingResult($blocked, $failed);
    }
}
