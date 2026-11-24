<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Events;

use DateTimeImmutable;

/**
 * A reservation moved to another start, asked by the customer or by the barbershop (`byBarbershop`). `collaboratorId`, `startsAt` and
 * `endsAt` are the new ones; `previousStartsAt` is the start it had. It carries its barbershop because a worker attends it.
 */
final readonly class ReservationRescheduled
{
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public string $customerMembershipId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $previousStartsAt,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public bool $byBarbershop,
    ) {}
}
