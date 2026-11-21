<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

/** What happens to the deposit paid online when a reservation is cancelled or missed (CA-02, CA-03, CA-04, RF-17). */
enum DepositOutcome: string
{
    /** The customer gets it back: the payment is marked to give back until the refund exists (RF-24). */
    case ToRefund = 'to_refund';

    /** The barbershop keeps it: the payment stays approved and nothing is marked. */
    case Retained = 'retained';

    /** There is no deposit paid online, so there is nothing to decide. */
    case None = 'none';
}
