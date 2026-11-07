<?php

declare(strict_types=1);

namespace Tests\Support\Reservations;

use Src\Reservations\Reservations\Application\Contracts\PaymentChannels;
use Src\Reservations\Reservations\Application\DTOs\PaymentChannel;

/** The same channel for every reservation asked, so the unit tests of Reservations do not need Payments. */
final readonly class FixedPaymentChannels implements PaymentChannels
{
    /** Build it with the gateway and methods every reservation gets. */
    public function __construct(private PaymentChannel $channel = new PaymentChannel('onvo', ['tarjeta', 'sinpe_movil'])) {}

    public function of(array $reservationIds): array
    {
        return array_fill_keys($reservationIds, $this->channel);
    }
}
