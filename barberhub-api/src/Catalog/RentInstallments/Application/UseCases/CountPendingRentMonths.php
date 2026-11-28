<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Domain\Contracts\RentInstallmentRepository;
use Src\Catalog\RentInstallments\Domain\Services\PendingRentMonths;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class CountPendingRentMonths
{
    /** Receive the installments port. */
    public function __construct(private RentInstallmentRepository $installments) {}

    /**
     * The pending months of a barbershop at this instant: its overdue unpaid installments, counted (PL-05, HO-03). The listing of the
     * operator and the blocking of a barbershop reuse it. The caller must have fixed the barbershop in context, because the installments
     * are read under the row security of the table.
     */
    public function execute(string $barbershopId, DateTimeImmutable $now): int
    {
        return PendingRentMonths::among($this->installments->unpaidOf(new BarbershopId($barbershopId)), $now);
    }
}
