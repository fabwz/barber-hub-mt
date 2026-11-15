<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\SellableVouchers;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherSales;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Barbershops\BarbershopsVoucherSales;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog\VoucherDefinitionsSellableVouchers;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Memberships\MembershipsCustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Repositories\EloquentPurchasedVoucherRepository;

final class PurchasedVouchersServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters. The purchase is paid and so it is exposed by Payments (#88); the module's own routes read
     * the balance and the validity (#90, in Http/routes.php); the use of vouchers arrives with #89.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        PurchasedVoucherRepository::class => EloquentPurchasedVoucherRepository::class,
        SellableVouchers::class => VoucherDefinitionsSellableVouchers::class,
        CustomerMemberships::class => MembershipsCustomerMemberships::class,
        VoucherSales::class => BarbershopsVoucherSales::class,
    ];
}
