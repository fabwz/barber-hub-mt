<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Application\Contracts;

use Src\Vouchers\PurchasedVouchers\Application\DTOs\PaymentChannel;

interface VoucherPaymentChannel
{
    /** Tell the gateway the current barbershop collects through now and the methods it admits; null when it does not charge (PA-01, PA-02). */
    public function current(): ?PaymentChannel;
}
