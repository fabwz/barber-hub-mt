<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

/** How a cancellation is classified: it decides the economic consequence, which #67 applies (CA-01, CA-04). */
enum CancellationClass: string
{
    /** The customer cancelled up to the free-cancellation deadline. */
    case OnTime = 'on_time';

    /** The customer cancelled after the deadline. */
    case Late = 'late';

    /** The barbershop cancelled: the customer recovers everything. */
    case ByBarbershop = 'by_barbershop';
}
