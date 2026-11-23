<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Domain\ValueObjects;

use DateTimeImmutable;

/** A confirmed reservation that a collaborator still has to attend: what the owner needs to find it and resolve it (CO-03). */
final readonly class UpcomingReservation
{
    /** Keep the identifier of the reservation, when it starts, the name of the service and the name of the customer. */
    public function __construct(
        public string $reservationId,
        public DateTimeImmutable $startsAt,
        public string $serviceName,
        public string $customerName,
    ) {}
}
