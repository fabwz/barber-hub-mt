<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Repositories\EloquentPurchasedVoucherRepository;

final class PurchasedVouchersServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters. The module has no routes yet: they arrive with the purchase, the use and the
     * query of vouchers (#88, #89 and #90).
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        PurchasedVoucherRepository::class => EloquentPurchasedVoucherRepository::class,
    ];
}
