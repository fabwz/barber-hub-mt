<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\ValueObjects;

use DateTimeImmutable;

/** A reservation, confirmed or temporary and still open, that falls inside the time the owner wants to block (CO-06). */
final readonly class ConflictingReservation
{
    /**
     * Keep what the owner needs to find the reservation and resolve it: its identifier, when it starts and ends, the name of
     * the service and of the customer, and its status as the API names it (`confirmada` or `temporal`).
     */
    public function __construct(
        public string $reservationId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public string $serviceName,
        public string $customerName,
        public string $status,
    ) {}
}
