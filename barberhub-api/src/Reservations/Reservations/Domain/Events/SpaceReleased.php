<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Events;

use DateTimeImmutable;

/**
 * A reservation stopped holding the time of a collaborator, because it was cancelled, it expired without payment or it moved to another
 * time (CA-07, RE-05, RE-08). The waitlist reacts to it to offer the space (RF-31). The event is plain data, so it can travel through the
 * queue, and it names its barbershop because the process that handles it has no request to take it from.
 */
final readonly class SpaceReleased
{
    /** Hold what freed the space: the reservation, the collaborator and the whole interval the reservation held, cleaning included. */
    public function __construct(
        public string $barbershopId,
        public string $reservationId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {}
}
