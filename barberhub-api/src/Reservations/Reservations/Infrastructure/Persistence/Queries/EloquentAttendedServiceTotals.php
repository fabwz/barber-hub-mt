<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Persistence\Queries;

use Src\Reservations\Reservations\Application\Contracts\AttendedServiceTotals;
use Src\Reservations\Reservations\Application\DTOs\AttendedServicesDay;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Infrastructure\Persistence\Models\ReservationModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

final readonly class EloquentAttendedServiceTotals implements AttendedServiceTotals
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Group the completed reservations of the barbershop by the day of Costa Rica they started on, summing their price in the database.
     *
     * @return list<AttendedServicesDay>
     */
    public function startedIn(ReportPeriod $period): array
    {
        $zone = ReportPeriod::TIMEZONE;
        $rows = ReservationModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('status', ReservationStatus::Completed->value)
            ->where('starts_at', '>=', $period->from->format(self::INSTANT_FORMAT))
            ->where('starts_at', '<', $period->until->format(self::INSTANT_FORMAT))
            ->selectRaw("to_char(starts_at AT TIME ZONE '{$zone}', 'YYYY-MM-DD') as day, count(*) as attended, coalesce(sum(price), 0) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->toBase()
            ->get();

        return array_values($rows->map(fn (object $row): AttendedServicesDay => new AttendedServicesDay((string) $row->day, (int) $row->attended, (int) $row->total))->all());
    }
}
