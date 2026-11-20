<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;

interface ReservationExpiries
{
    /**
     * Leave a task for the moment the payment window of a temporary reservation closes (RE-05), so the reservation expires and frees its
     * space on time and not only when somebody asks for the collaborator. The task is only a trigger: it finds the reservation again and
     * expires it if it is still temporary and overdue, so one that was paid or cancelled meanwhile is left alone.
     */
    public function schedule(string $reservationId, DateTimeImmutable $at): void;
}
