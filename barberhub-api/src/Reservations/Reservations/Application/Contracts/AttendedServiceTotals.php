<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Application\DTOs\AttendedServicesDay;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

interface AttendedServiceTotals
{
    /**
     * Add up, per day, the completed reservations of the current barbershop whose service started inside the period, with the price
     * each kept. The sums are made by the database, so no reservation is loaded. A day without attended services is left out.
     *
     * @return list<AttendedServicesDay> The earliest day first.
     */
    public function startedIn(ReportPeriod $period): array;
}
