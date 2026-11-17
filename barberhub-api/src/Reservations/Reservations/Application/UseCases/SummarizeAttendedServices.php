<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use Src\Reservations\Reservations\Application\Contracts\AttendedServiceTotals;
use Src\Reservations\Reservations\Application\DTOs\AttendedServicesDay;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

final readonly class SummarizeAttendedServices
{
    /** Receive the port that adds up the attended services. */
    public function __construct(private AttendedServiceTotals $totals) {}

    /**
     * Tell, per day, how many services the current barbershop attended inside the period and what they are worth (RF-25, PA-11). A
     * service is attended when its reservation is completed (RE-04) and it counts on the day it started, at the full price the
     * reservation kept, however it was paid: with a deposit, a voucher session or in the barbershop. It is read-only and belongs
     * to the barbershop of the route (MT-02).
     *
     * @return list<AttendedServicesDay>
     */
    public function execute(ReportPeriod $period): array
    {
        return $this->totals->startedIn($period);
    }
}
