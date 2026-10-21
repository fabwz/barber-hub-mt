<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

use DateTimeImmutable;

/** One entry of the history of a reservation: from which status to which, by whom and when (RE-09). */
final readonly class ReservationStatusChange
{
    /** Hold the change; the creation of a reservation has no previous status. */
    public function __construct(
        public ?ReservationStatus $from,
        public ReservationStatus $to,
        public Author $author,
        public DateTimeImmutable $at,
    ) {}
}
