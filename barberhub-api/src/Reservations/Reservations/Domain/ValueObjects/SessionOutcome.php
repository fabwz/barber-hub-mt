<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

/** What happens to the session of a voucher that pays a reservation (BO-05, CA-02, CA-03). */
enum SessionOutcome: string
{
    /** The session goes back to the balance of the voucher. */
    case Released = 'released';

    /** The session stays used. */
    case Consumed = 'consumed';

    /** The reservation was not paid with a voucher. */
    case None = 'none';
}
