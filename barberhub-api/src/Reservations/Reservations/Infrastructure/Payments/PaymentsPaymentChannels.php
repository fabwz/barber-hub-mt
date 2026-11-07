<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Payments;

use Src\Payments\Payments\Application\DTOs\PaymentChannel as PaymentsChannel;
use Src\Payments\Payments\Application\UseCases\QuotePaymentChannels;
use Src\Payments\Payments\Infrastructure\Http\PaymentNames;
use Src\Reservations\Reservations\Application\Contracts\PaymentChannels;
use Src\Reservations\Reservations\Application\DTOs\PaymentChannel;

final readonly class PaymentsPaymentChannels implements PaymentChannels
{
    /** Receive the Payments use case that tells the channel of each reservation. */
    public function __construct(private QuotePaymentChannels $quote) {}

    /** Ask Payments, which owns the gateways, and name the methods as the API does. */
    public function of(array $reservationIds): array
    {
        return array_map(
            fn (PaymentsChannel $channel): PaymentChannel => new PaymentChannel(
                $channel->provider->value,
                array_map(PaymentNames::method(...), $channel->methods),
            ),
            $this->quote->execute($reservationIds),
        );
    }
}
