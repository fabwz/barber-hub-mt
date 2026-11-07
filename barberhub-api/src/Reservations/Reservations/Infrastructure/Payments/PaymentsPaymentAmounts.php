<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Payments;

use Src\Payments\Payments\Application\UseCases\QuoteCharge;
use Src\Reservations\Reservations\Application\Contracts\PaymentAmounts;

final readonly class PaymentsPaymentAmounts implements PaymentAmounts
{
    /** Receive the Payments use case that quotes a charge. */
    public function __construct(private QuoteCharge $quoteCharge) {}

    /** Ask Payments, which owns the calculation of the charge. */
    public function dueFor(array $prices): array
    {
        return $this->quoteCharge->execute($prices);
    }
}
