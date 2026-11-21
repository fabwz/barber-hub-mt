<?php

declare(strict_types=1);

namespace Tests\Unit\Reservations\Reservations\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Reservations\Reservations\Domain\Services\CancellationConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\DepositOutcome;
use Src\Reservations\Reservations\Domain\ValueObjects\EconomicConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\SessionOutcome;

/** The table of cases of the economic consequence of a cancellation or an absence, row by row (RF-17, CA-02, CA-03, CA-04, BO-05). */
final class CancellationConsequenceTest extends TestCase
{
    /**
     * Every class of cancellation with and without a deposit paid and with and without a voucher.
     *
     * @return iterable<string, array{CancellationClass, bool, bool, DepositOutcome, SessionOutcome}>
     */
    public static function cancellations(): iterable
    {
        yield 'on time, deposit' => [CancellationClass::OnTime, true, false, DepositOutcome::ToRefund, SessionOutcome::None];
        yield 'on time, voucher' => [CancellationClass::OnTime, false, true, DepositOutcome::None, SessionOutcome::Released];
        yield 'on time, nothing paid' => [CancellationClass::OnTime, false, false, DepositOutcome::None, SessionOutcome::None];
        yield 'late, deposit' => [CancellationClass::Late, true, false, DepositOutcome::Retained, SessionOutcome::None];
        yield 'late, voucher' => [CancellationClass::Late, false, true, DepositOutcome::None, SessionOutcome::Consumed];
        yield 'late, nothing paid' => [CancellationClass::Late, false, false, DepositOutcome::None, SessionOutcome::None];
        yield 'by the barbershop, deposit' => [CancellationClass::ByBarbershop, true, false, DepositOutcome::ToRefund, SessionOutcome::None];
        yield 'by the barbershop, voucher' => [CancellationClass::ByBarbershop, false, true, DepositOutcome::None, SessionOutcome::Released];
        yield 'by the barbershop, nothing paid' => [CancellationClass::ByBarbershop, false, false, DepositOutcome::None, SessionOutcome::None];
    }

    /** A cancellation gives back on time and by the barbershop, and keeps when late (CA-02, CA-03, CA-04, BO-05). */
    #[DataProvider('cancellations')]
    public function test_a_cancellation_follows_the_table(CancellationClass $class, bool $deposit, bool $voucher, DepositOutcome $expectedDeposit, SessionOutcome $expectedSession): void
    {
        $consequence = new CancellationConsequence()->forCancellation($class, $deposit, $voucher);

        $this->assertSame($expectedDeposit, $consequence->deposit);
        $this->assertSame($expectedSession, $consequence->session);
    }

    /** An absence retains the deposit and consumes the session (CA-03, BO-05). */
    public function test_an_absence_retains_and_consumes(): void
    {
        $service = new CancellationConsequence;

        $this->assertSame([DepositOutcome::Retained, SessionOutcome::None], $this->pair($service->forAbsence(true, false)));
        $this->assertSame([DepositOutcome::None, SessionOutcome::Consumed], $this->pair($service->forAbsence(false, true)));
        $this->assertSame([DepositOutcome::None, SessionOutcome::None], $this->pair($service->forAbsence(false, false)));
    }

    /** An attended reservation decides nothing about the deposit and uses the session (CA-03). */
    public function test_an_attended_reservation_changes_no_deposit(): void
    {
        $service = new CancellationConsequence;

        $this->assertSame([DepositOutcome::None, SessionOutcome::Consumed], $this->pair($service->forAttended(true)));
        $this->assertSame([DepositOutcome::None, SessionOutcome::None], $this->pair($service->forAttended(false)));
    }

    /**
     * The two halves of a consequence.
     *
     * @return array{DepositOutcome, SessionOutcome}
     */
    private function pair(EconomicConsequence $consequence): array
    {
        return [$consequence->deposit, $consequence->session];
    }
}
