<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CoveringDefinitions;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\CustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\DefinitionServices;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\OnSaleVouchers;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\SellableVouchers;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\SoldVoucherTotals;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherPaymentChannel;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherSales;
use Src\Vouchers\PurchasedVouchers\Domain\Contracts\PurchasedVoucherRepository;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Barbershops\BarbershopsVoucherSales;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog\VoucherDefinitionsCoveringDefinitions;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog\VoucherDefinitionsDefinitionServices;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog\VoucherDefinitionsOnSaleVouchers;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Catalog\VoucherDefinitionsSellableVouchers;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Memberships\MembershipsCustomerMemberships;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Payments\PaymentsVoucherPaymentChannel;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Queries\EloquentSoldVoucherTotals;
use Src\Vouchers\PurchasedVouchers\Infrastructure\Persistence\Repositories\EloquentPurchasedVoucherRepository;

final class PurchasedVouchersServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters. The purchase is paid and so it is exposed by Payments (#88); the module's own routes read
     * the balance and the validity (#90, in Http/routes.php); the sessions are set aside, given back and used by the use cases the reservations call (#89).
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        PurchasedVoucherRepository::class => EloquentPurchasedVoucherRepository::class,
        SellableVouchers::class => VoucherDefinitionsSellableVouchers::class,
        CustomerMemberships::class => MembershipsCustomerMemberships::class,
        VoucherSales::class => BarbershopsVoucherSales::class,
        CoveringDefinitions::class => VoucherDefinitionsCoveringDefinitions::class,
        OnSaleVouchers::class => VoucherDefinitionsOnSaleVouchers::class,
        DefinitionServices::class => VoucherDefinitionsDefinitionServices::class,
        SoldVoucherTotals::class => EloquentSoldVoucherTotals::class,
        VoucherPaymentChannel::class => PaymentsVoucherPaymentChannel::class,
    ];
}
