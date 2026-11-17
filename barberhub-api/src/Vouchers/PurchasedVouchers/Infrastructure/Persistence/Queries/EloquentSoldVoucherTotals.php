<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Queries;

use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\ReportPeriod;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\SoldVoucherTotals;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VoucherSalesDay;
use Src\Vouchers\PurchasedVouchers\Domain\ValueObjects\VoucherStatus;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Models\PurchasedVoucherModel;

final readonly class EloquentSoldVoucherTotals implements SoldVoucherTotals
{
    /** Instants travel as text with their offset, so they never depend on the time zone of the process. */
    private const string INSTANT_FORMAT = 'Y-m-d H:i:s.uP';

    /** Receive the port that tells which barbershop the operation belongs to. */
    public function __construct(private BarbershopContext $context) {}

    /**
     * Group the active vouchers of the barbershop by the day of Costa Rica they were activated on, summing their price in the database.
     *
     * @return list<VoucherSalesDay>
     */
    public function activatedIn(ReportPeriod $period): array
    {
        $zone = ReportPeriod::TIMEZONE;
        $rows = PurchasedVoucherModel::query()
            ->where('barbershop_id', $this->context->current()->value)
            ->where('status', VoucherStatus::Active->value)
            ->where('activated_at', '>=', $period->from->format(self::INSTANT_FORMAT))
            ->where('activated_at', '<', $period->until->format(self::INSTANT_FORMAT))
            ->selectRaw("to_char(activated_at AT TIME ZONE '{$zone}', 'YYYY-MM-DD') as day, count(*) as sold, coalesce(sum(price), 0) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->toBase()
            ->get();

        return array_values($rows->map(fn (object $row): VoucherSalesDay => new VoucherSalesDay((string) $row->day, (int) $row->sold, (int) $row->total))->all());
    }
}
