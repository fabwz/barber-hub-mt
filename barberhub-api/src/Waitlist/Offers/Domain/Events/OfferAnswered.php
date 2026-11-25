<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Events;

/**
 * The person answered the offer they were made: they turned it down, or their reservation was confirmed and the offer became accepted (RF-32).
 * The pop-up of the offer reacts to it, as it disappears when the offer is answered (RF-35). The event is plain data, names its barbershop
 * as the others of the line do, and says the answer with the status the offer took, `accepted` or `rejected`.
 */
final readonly class OfferAnswered
{
    /** Hold the offer, the customer role it was offered to and the status it took. */
    public function __construct(
        public string $barbershopId,
        public string $offerId,
        public string $customerMembershipId,
        public string $status,
    ) {}
}
