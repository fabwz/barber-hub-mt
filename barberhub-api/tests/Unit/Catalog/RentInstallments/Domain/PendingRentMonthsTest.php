<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\RentInstallments\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Catalog\RentInstallments\Domain\Entities\RentInstallment;
use Src\Catalog\RentInstallments\Domain\Services\PendingRentMonths;
use Src\Catalog\RentInstallments\Domain\ValueObjects\InstallmentStatus;
use Src\Catalog\RentInstallments\Domain\ValueObjects\RentInstallmentId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;

/** The pending months are counted from the overdue unpaid installments; nothing stores them (PL-05). */
final class PendingRentMonthsTest extends TestCase
{
    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    public function test_a_barbershop_without_overdue_installments_has_no_pending_months(): void
    {
        $this->assertSame(0, PendingRentMonths::among([], new DateTimeImmutable('2026-11-20 10:00:00-06:00')));
        $this->assertSame(0, PendingRentMonths::among([$this->installment('2026-12-10')], new DateTimeImmutable('2026-11-20 10:00:00-06:00')));
    }

    public function test_one_overdue_unpaid_installment_is_one_pending_month(): void
    {
        $installments = [$this->installment('2026-11-10'), $this->installment('2026-12-10')];

        $this->assertSame(1, PendingRentMonths::among($installments, new DateTimeImmutable('2026-11-20 10:00:00-06:00')));
    }

    public function test_three_overdue_unpaid_installments_are_three_pending_months(): void
    {
        $installments = [$this->installment('2026-09-10'), $this->installment('2026-10-10'), $this->installment('2026-11-10'), $this->installment('2026-12-10')];

        $this->assertSame(3, PendingRentMonths::among($installments, new DateTimeImmutable('2026-11-20 10:00:00-06:00')));
    }

    public function test_a_paid_installment_is_not_a_pending_month(): void
    {
        $installments = [$this->installment('2026-09-10', InstallmentStatus::Paid), $this->installment('2026-10-10')];

        $this->assertSame(1, PendingRentMonths::among($installments, new DateTimeImmutable('2026-11-20 10:00:00-06:00')));
    }

    /** On its due day an installment is not overdue yet; it is from midnight of Costa Rica on (HO-03), not from midnight in UTC. */
    public function test_the_due_day_turns_into_overdue_at_midnight_in_costa_rica(): void
    {
        $installments = [$this->installment('2026-11-10')];

        $this->assertSame(0, PendingRentMonths::among($installments, new DateTimeImmutable('2026-11-10 12:00:00-06:00')));
        $this->assertSame(0, PendingRentMonths::among($installments, new DateTimeImmutable('2026-11-11 05:59:59+00:00')));
        $this->assertSame(1, PendingRentMonths::among($installments, new DateTimeImmutable('2026-11-11 06:00:00+00:00')));
    }

    private function installment(string $dueOn, InstallmentStatus $status = InstallmentStatus::Pending): RentInstallment
    {
        return RentInstallment::reconstitute(
            new RentInstallmentId('0192f0c4-7b1e-7c3a-9f1d-'.str_pad((string) random_int(1, 999999), 12, '0', STR_PAD_LEFT)),
            new BarbershopId(self::BARBERSHOP),
            Money::of(25000),
            new DateTimeImmutable($dueOn, new DateTimeZone('America/Costa_Rica')),
            $status,
        );
    }
}
