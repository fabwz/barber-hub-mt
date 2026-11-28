<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Domain\Services;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Domain\Entities\RentInstallment;

/** The pending months of a barbershop: its installments already overdue and unpaid, counted; nothing stores the number (PL-05). */
final readonly class PendingRentMonths
{
    /**
     * Count the overdue unpaid installments at this instant.
     *
     * @param  list<RentInstallment>  $installments  The installments of one barbershop.
     */
    public static function among(array $installments, DateTimeImmutable $now): int
    {
        return count(array_filter($installments, static fn (RentInstallment $installment): bool => $installment->isOverdueAt($now)));
    }
}
