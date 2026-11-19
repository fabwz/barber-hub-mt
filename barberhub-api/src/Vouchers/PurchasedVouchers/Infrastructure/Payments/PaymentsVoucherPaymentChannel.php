<?php

declare(strict_types=1);

namespace Src\Vouchers\PurchasedVouchers\Infrastructure\Payments;

use Src\Payments\Payments\Application\UseCases\QuoteCurrentPaymentChannel;
use Src\Payments\Payments\Infrastructure\Http\PaymentNames;
use Src\Vouchers\PurchasedVouchers\Application\Contracts\VoucherPaymentChannel;
use Src\Vouchers\PurchasedVouchers\Application\DTOs\PaymentChannel;

final readonly class PaymentsVoucherPaymentChannel implements VoucherPaymentChannel
{
    /** Receive the Payments use case that tells the channel of the current barbershop. */
    public function __construct(private QuoteCurrentPaymentChannel $quote) {}

    /** Ask Payments, which owns the gateways, and name the methods as the API does. */
    public function current(): ?PaymentChannel
    {
        $channel = $this->quote->execute();

        return $channel === null ? null : new PaymentChannel($channel->provider->value, array_map(PaymentNames::method(...), $channel->methods));
    }
}
