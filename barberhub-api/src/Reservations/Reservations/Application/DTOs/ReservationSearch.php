<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

final readonly class ReservationSearch
{
    /**
     * Carry what a person filters their reservations by. The day is the instant it begins in Costa Rica; the collaborator
     * only means something to the staff.
     */
    public function __construct(
        public ?DateTimeImmutable $day = null,
        public ?ReservationStatus $status = null,
        public ?string $collaboratorId = null,
    ) {}
}
