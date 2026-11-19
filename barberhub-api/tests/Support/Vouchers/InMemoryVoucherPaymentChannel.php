<?php

declare(strict_types=1);

namespace Tests\Support\Vouchers;

use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherPaymentChannel;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PaymentChannel;

/** The gateway of the barbershop of the test; it starts with none, as a barbershop that does not charge. */
final class InMemoryVoucherPaymentChannel implements VoucherPaymentChannel
{
    /** Keep the channel, null when the barbershop does not charge. */
    public function __construct(private ?PaymentChannel $channel = null) {}

    /** Tell the channel the barbershop has now. */
    public function current(): ?PaymentChannel
    {
        return $this->channel;
    }
}
