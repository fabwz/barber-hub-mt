<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Events;

use DateTimeImmutable;

/**
 * A reservation was cancelled, by the customer or by the barbershop (`byBarbershop`). It is published once, when the cancellation really
 * happens, not when a repeated request finds the reservation already cancelled (CA-06). It carries its barbershop because a worker
 * attends it and has no request to take it from.
 */
final readonly class ReservationCancelled
{
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public string $customerMembershipId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public bool $byBarbershop,
    ) {}
}
