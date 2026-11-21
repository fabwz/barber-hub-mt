<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Services;

use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\DepositOutcome;
use Src\Reservations\Reservations\Domain\ValueObjects\EconomicConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\SessionOutcome;

/**
 * The table of cases of the economic consequence (RF-17): pure rules, with no clock and no framework; the caller classifies the
 * cancellation with the time it knows.
 *
 * | Case                    | Deposit paid online | Session of the voucher |
 * | cancelled on time       | to refund (CA-02)   | released (BO-05)       |
 * | cancelled by barbershop | to refund (CA-04)   | released (BO-05)       |
 * | cancelled late          | retained (CA-03)    | consumed (BO-05)       |
 * | absence                 | retained (CA-03)    | consumed (BO-05)       |
 * | attended                | applied, no change  | consumed               |
 *
 * Without a deposit paid the deposit column is none, and without a voucher the session column is none.
 */
final class CancellationConsequence
{
    /** What a cancellation of the given class does to the deposit and to the session. */
    public function forCancellation(CancellationClass $class, bool $hasPaidDeposit, bool $paidWithVoucher): EconomicConsequence
    {
        $giveBack = $class !== CancellationClass::Late;

        return new EconomicConsequence(
            $hasPaidDeposit ? ($giveBack ? DepositOutcome::ToRefund : DepositOutcome::Retained) : DepositOutcome::None,
            $paidWithVoucher ? ($giveBack ? SessionOutcome::Released : SessionOutcome::Consumed) : SessionOutcome::None,
        );
    }

    /** What an absence does: the deposit is retained and the session is used (CA-03). */
    public function forAbsence(bool $hasPaidDeposit, bool $paidWithVoucher): EconomicConsequence
    {
        return new EconomicConsequence(
            $hasPaidDeposit ? DepositOutcome::Retained : DepositOutcome::None,
            $paidWithVoucher ? SessionOutcome::Consumed : SessionOutcome::None,
        );
    }

    /** What an attended reservation does: the deposit is part of the price and nothing changes; the session is used. */
    public function forAttended(bool $paidWithVoucher): EconomicConsequence
    {
        return new EconomicConsequence(DepositOutcome::None, $paidWithVoucher ? SessionOutcome::Consumed : SessionOutcome::None);
    }
}
