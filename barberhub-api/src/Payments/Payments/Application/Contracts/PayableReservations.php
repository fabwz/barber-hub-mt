<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use DateTimeImmutable;
use Src\Payments\Payments\Application\DTOs\PayableReservation;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;

interface PayableReservations
{
    /**
     * The reservation of the current barbershop that belongs to the customer, with the price it kept and whether it still waits for its payment at the instant.
     *
     * @throws PaymentNotFoundException When it does not exist, is of another barbershop or of another customer (UP-06).
     */
    public function ofCustomer(string $userId, string $reservationId, DateTimeImmutable $now): PayableReservation;
}
