<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopScope;
use Src\Catalog\RentInstallments\Application\Contracts\RegisteredBarbershops;
use Src\Catalog\RentInstallments\Domain\Contracts\RentAmount;
use Src\Catalog\RentInstallments\Domain\Contracts\RentInstallmentRepository;
use Src\Catalog\RentInstallments\Domain\Entities\RentInstallment;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class EnsureRentInstallments
{
    /** Receive the ports: the barbershops, the scope of a barbershop, its installments and the rent in force. */
    public function __construct(
        private RegisteredBarbershops $barbershops,
        private BarbershopScope $scope,
        private RentInstallmentRepository $installments,
        private RentAmount $rent,
    ) {}

    /**
     * Create the installments a barbershop is missing at this instant and return how many were created (RF-57). The first one when it has
     * none, which is what registering a barbershop does; later, the month that follows once the latest one is over. It runs as that
     * barbershop, so it works with no session. Running it again finds nothing to add: the unique key of the table decides (PL-05).
     */
    public function execute(string $barbershopId, DateTimeImmutable $now): int
    {
        $registeredAt = $this->barbershops->registeredAt($barbershopId);

        if ($registeredAt === null) {
            return 0;
        }

        return $this->scope->run($barbershopId, function () use ($barbershopId, $registeredAt, $now): int {
            $id = new BarbershopId($barbershopId);
            $dueDates = RentCalendar::registeredAt($registeredAt)->missingDueDates($this->installments->latestDueOn($id), $now);
            $created = 0;

            foreach ($dueDates as $dueOn) {
                $installment = RentInstallment::create($this->installments->nextId(), $id, $this->rent->current(), $dueOn);

                if ($this->installments->addIfMissing($installment)) {
                    $created++;
                }
            }

            return $created;
        });
    }
}
