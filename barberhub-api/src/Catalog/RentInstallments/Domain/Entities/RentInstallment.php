<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Domain\Entities;

use DateTimeImmutable;
use Src\Catalog\RentInstallments\Domain\Services\RentCalendar;
use Src\Catalog\RentInstallments\Domain\ValueObjects\InstallmentStatus;
use Src\Catalog\RentInstallments\Domain\ValueObjects\RentInstallmentId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;

/** The rent of one month of a barbershop, with the amount it was created with and the day it falls due (PL-05). */
final readonly class RentInstallment
{
    /** Hold the full state of an installment; the due date is a calendar day at midnight of Costa Rica. */
    private function __construct(
        public RentInstallmentId $id,
        public BarbershopId $barbershopId,
        public Money $amount,
        public DateTimeImmutable $dueOn,
        public InstallmentStatus $status,
    ) {}

    /** Create a pending installment; the amount is copied now and never follows the current rent again. */
    public static function create(RentInstallmentId $id, BarbershopId $barbershopId, Money $amount, DateTimeImmutable $dueOn): self
    {
        return new self($id, $barbershopId, $amount, $dueOn, InstallmentStatus::Pending);
    }

    /** Rebuild an installment from its stored state. */
    public static function reconstitute(RentInstallmentId $id, BarbershopId $barbershopId, Money $amount, DateTimeImmutable $dueOn, InstallmentStatus $status): self
    {
        return new self($id, $barbershopId, $amount, $dueOn, $status);
    }

    /** Whether it counts as a pending month at this instant: unpaid and its due day is already over, in Costa Rica time (PL-05, HO-03). */
    public function isOverdueAt(DateTimeImmutable $now): bool
    {
        return $this->status === InstallmentStatus::Pending && $this->dueOn < RentCalendar::dayOf($now);
    }
}
