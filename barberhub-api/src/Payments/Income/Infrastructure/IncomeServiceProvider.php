<?php

declare(strict_types=1);

namespace Src\Payments\Income\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Payments\Income\Application\Contracts\AttendedServices;
use Src\Payments\Income\Application\Contracts\OnlinePaymentTotals;
use Src\Payments\Income\Application\Contracts\SoldVouchers;
use Src\Payments\Income\Infrastructure\Persistence\Queries\DatabaseOnlinePaymentTotals;
use Src\Payments\Income\Infrastructure\Reservations\ReservationsAttendedServices;
use Src\Payments\Income\Infrastructure\Vouchers\VouchersSoldVouchers;

final class IncomeServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters: the payments are read from the tables of Payments, and the vouchers sold and the
     * services attended come from the query use cases of Vouchers and Reservations, which read their own tables.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        OnlinePaymentTotals::class => DatabaseOnlinePaymentTotals::class,
        SoldVouchers::class => VouchersSoldVouchers::class,
        AttendedServices::class => ReservationsAttendedServices::class,
    ];
}
