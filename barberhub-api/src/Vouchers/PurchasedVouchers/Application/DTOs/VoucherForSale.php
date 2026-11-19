<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\DTOs;

/** A voucher on sale as the customer sees it before buying: the terms it is sold with and the services it covers (RF-27, BO-06). */
final readonly class VoucherForSale
{
    /** @param  list<CoveredService>  $services  Ordered by name. */
    public function __construct(public VoucherOnSale $terms, public array $services) {}
}
