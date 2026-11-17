<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\DTOs;

/** The services attended on one day of Costa Rica: how many and the value they kept when they were booked, in colones. */
final readonly class AttendedServicesDay
{
    /** Hold the day as `YYYY-MM-DD`, the number of reservations and the sum of their prices. */
    public function __construct(public string $date, public int $count, public int $total) {}
}
