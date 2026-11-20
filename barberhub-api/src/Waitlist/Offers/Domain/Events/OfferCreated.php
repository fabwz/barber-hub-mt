<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Events;

use DateTimeImmutable;

/**
 * The line made an offer to a person: the space is held for them until it expires (RF-31, LE-04). The notices react to it, the email (RF-34) and
 * the pop-up (RF-35), which belong to other issues; nothing is sent here. The event is plain data, so it can travel through a queue, and it names
 * its barbershop because the process that handles it has no request to take it from.
 */
final readonly class OfferCreated
{
    /** Hold what a notice needs to tell the person: the offer, who it is for, what they wait for, the space held and until when. */
    public function __construct(
        public string $barbershopId,
        public string $offerId,
        public string $waitlistEntryId,
        public string $customerMembershipId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
