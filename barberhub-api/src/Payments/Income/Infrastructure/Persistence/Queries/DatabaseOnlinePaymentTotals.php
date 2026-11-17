<?php

declare(strict_types=1);

namespace Src\Payments\Income\Infrastructure\Persistence\Queries;

use Illuminate\Support\Facades\DB;
use Src\Payments\Income\Application\Contracts\OnlinePaymentTotals;
use Src\Payments\Income\Application\DTOs\DayAmount;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\ReportPeriod;

final readonly class DatabaseOnlinePaymentTotals implements OnlinePaymentTotals
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Sum the approved and the refunded payments of reservations by the day of Costa Rica they were created on. The payment does not
     * keep when it was approved, so its creation (moments before) dates the charge; RF-24 will bring its own dates.
     *
     * @return list<DayAmount>
     */
    public function chargedIn(ReportPeriod $period): array
    {
        return $this->grouped($period, 'created_at', [PaymentStatus::Approved->value, PaymentStatus::Refunded->value]);
    }

    /**
     * Sum the refunded payments by the day of Costa Rica of their last change. A refunded payment is final, so that change is the refund;
     * RF-24 will bring its own dates.
     *
     * @return list<DayAmount>
     */
    public function refundedIn(ReportPeriod $period): array
    {
        return $this->grouped($period, 'updated_at', [PaymentStatus::Refunded->value]);
    }

    /**
     * Group the payments of reservations of the barbershop with one of the statuses by the day of a timestamp column, in the database.
     *
     * @param  'created_at'|'updated_at'  $column
     * @param  list<string>  $statuses
     * @return list<DayAmount>
     */
    private function grouped(ReportPeriod $period, string $column, array $statuses): array
    {
        $zone = ReportPeriod::TIMEZONE;
        $day = match ($column) {
            'created_at' => "to_char(created_at AT TIME ZONE '{$zone}', 'YYYY-MM-DD')",
            'updated_at' => "to_char(updated_at AT TIME ZONE '{$zone}', 'YYYY-MM-DD')",
        };
        $rows = DB::table('payments')
            ->where('barbershop_id', $this->context->current()->value)
            ->whereNotNull('reservation_id')
            ->whereIn('status', $statuses)
            ->where($column, '>=', $period->from->format(self::INSTANT_FORMAT))
            ->where($column, '<', $period->until->format(self::INSTANT_FORMAT))
            ->selectRaw("{$day} as day, count(*) as entries, coalesce(sum(amount), 0) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return array_values($rows->map(fn (object $row): DayAmount => new DayAmount((string) $row->day, (int) $row->entries, (int) $row->total))->all());
    }
}
