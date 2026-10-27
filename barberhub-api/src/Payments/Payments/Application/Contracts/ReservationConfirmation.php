<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use DateTimeImmutable;

interface ReservationConfirmation
{
    /**
     * Confirm the temporary reservation whose payment was approved. It joins the transaction of the caller. It answers
     * false when the reservation can no longer be confirmed (its window closed, it is no longer temporary or the customer
     * got another reservation at that time), so the caller does not leave a paid space the system cannot give (PA-07).
     */
    public function confirm(string $reservationId, DateTimeImmutable $now): bool;
}
