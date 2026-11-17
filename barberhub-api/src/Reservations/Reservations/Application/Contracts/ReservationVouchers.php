<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;

interface ReservationVouchers
{
    /**
     * Set a session of a voucher of the customer aside for the reservation and return the identifier of the voucher it came from. It
     * chooses the voucher that expires first among the valid ones that cover the service and never goes below zero (RF-28, BO-03,
     * BO-06, BO-08). It refuses with `saldo-insuficiente`, `bono-vencido` or a conflict when no voucher applies. The reservation must
     * already be stored, because the record of the voucher points at it; the whole operation runs in the transaction of the reservation.
     */
    public function hold(string $customerMembershipId, string $serviceId, string $reservationId, DateTimeImmutable $startsAt, DateTimeImmutable $now): string;

    /** Give the session of the reservation back to its voucher: the cancellation was on time or the barbershop's (BO-05, CA-02). Settling twice changes nothing (CA-06). */
    public function release(string $voucherId, string $reservationId, DateTimeImmutable $now): void;

    /** Leave the session of the reservation used: the cancellation was late or the customer did not show up (BO-05, CA-03). Settling twice changes nothing (CA-06). */
    public function consume(string $voucherId, string $reservationId, DateTimeImmutable $now): void;
}
