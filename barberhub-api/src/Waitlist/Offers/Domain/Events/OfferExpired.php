<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Events;

/**
 * An offer ran out its 15 minutes without an answer and the line moved on (LE-04, LE-05). The pop-up of the offer reacts to it, as it disappears
 * when the offer expires (RF-35). The event is plain data and names its barbershop, as the others of the line do.
 */
final readonly class OfferExpired
{
    /** Hold the offer that expired and the row of the line it was made to, from which the person is found. */
    public function __construct(
        public string $barbershopId,
        public string $offerId,
        public string $waitlistEntryId,
    ) {}
}
