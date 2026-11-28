<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Domain\Contracts;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Domain\Entities\RentInstallment;
use Src\Catalog\RentInstallments\Domain\ValueObjects\RentInstallmentId;
use Src\Shared\Domain\ValueObjects\BarbershopId;

interface RentInstallmentRepository
{
    /** Generate a time-ordered identifier for a new installment. */
    public function nextId(): RentInstallmentId;

    /** Save the installment unless the barbershop already has one with that due date; true when it was saved (idempotency of the generation). */
    public function addIfMissing(RentInstallment $installment): bool;

    /** The due date of the latest installment of the barbershop, or null when it has none. */
    public function latestDueOn(BarbershopId $barbershopId): ?DateTimeImmutable;

    /**
     * The installments of the barbershop that are not paid.
     *
     * @return list<RentInstallment>
     */
    public function unpaidOf(BarbershopId $barbershopId): array;
}
