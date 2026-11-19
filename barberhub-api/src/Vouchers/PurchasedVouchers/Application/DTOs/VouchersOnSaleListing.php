<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Page;

/** What the customer reads of the shelf of a barbershop: whether it sells vouchers and, if so, one page of them. */
final readonly class VouchersOnSaleListing
{
    /** @param  Page<VoucherForSale>  $page */
    public function __construct(public bool $enabled, public Page $page) {}
}
