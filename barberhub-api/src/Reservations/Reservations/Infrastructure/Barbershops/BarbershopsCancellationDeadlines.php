<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Barbershops;

use Src\Catalog\Barbershops\Application\UseCases\GetPaymentPolicy;
use Src\Reservations\Reservations\Application\Contracts\CancellationDeadlines;

final readonly class BarbershopsCancellationDeadlines implements CancellationDeadlines
{
    /** Receive the Barbershops use case that gives the payment policy. */
    public function __construct(private GetPaymentPolicy $getPaymentPolicy) {}

    /** Give the free-cancellation deadline of the current barbershop through the Barbershops module. */
    public function current(): int
    {
        return $this->getPaymentPolicy->execute()->cancellationDeadlineHours;
    }
}
