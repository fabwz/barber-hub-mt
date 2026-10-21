<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

final readonly class ReservationHistoryEntry
{
    /** Carry one change of status with the name of its author, or none when the system made it (RE-09). */
    public function __construct(
        public ?ReservationStatus $from,
        public ReservationStatus $to,
        public ?string $authorName,
        public DateTimeImmutable $at,
    ) {}
}
