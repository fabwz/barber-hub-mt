<?php

declare(strict_types=1);

namespace Src\Payments\Income\Infrastructure\Reservations;

use Src\Payments\Income\Application\Contracts\AttendedServices;
use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Reservations\Reservations\Application\DTOs\AttendedServicesDay;
use Src\Reservations\Reservations\Application\UseCases\SummarizeAttendedServices;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

final readonly class ReservationsAttendedServices implements AttendedServices
{
    /** Receive the Reservations use case that adds up the attended services. */
    public function __construct(private SummarizeAttendedServices $summarize) {}

    /**
     * Ask Reservations for its attended services and keep only the day, the number and the sum.
     *
     * @return list<DayAmount>
     */
    public function in(ReportPeriod $period): array
    {
        return array_map(fn (AttendedServicesDay $day): DayAmount => new DayAmount($day->date, $day->count, $day->total), $this->summarize->execute($period));
    }
}
