<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\UseCases;

use Src\Shared\Domain\ValueObjects\Page;
use Src\Shared\Domain\ValueObjects\PageRequest;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\OnSaleVouchers;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherSales;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\VouchersOnSaleListing;

final readonly class ListVouchersOnSale
{
    /** Receive the port that lists the vouchers on sale and the one that tells whether the barbershop sells them. */
    public function __construct(private OnSaleVouchers $onSale, private VoucherSales $sales) {}

    /**
     * List one page of the vouchers the current barbershop sells, with the services each covers, so a customer can choose one to buy
     * (RF-27). With the vouchers switched off the page is empty and the answer says so (RF-09, BO-01). It is a read of the shelf: it
     * creates no role, and who may call it is decided by the route (RD-18, UP-09).
     */
    public function execute(PageRequest $page): VouchersOnSaleListing
    {
        if (! $this->sales->isEnabled()) {
            return new VouchersOnSaleListing(false, new Page([], 0, $page));
        }

        return new VouchersOnSaleListing(true, $this->onSale->page($page));
    }
}
