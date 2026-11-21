<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

/** The economic consequence of closing or cancelling a reservation: what happens to its deposit and to its voucher session (RF-17). */
final readonly class EconomicConsequence
{
    /** Pair what happens to the deposit with what happens to the session. */
    public function __construct(public DepositOutcome $deposit, public SessionOutcome $session) {}
}
