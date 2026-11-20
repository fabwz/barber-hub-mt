<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

use DateTimeImmutable;

interface OfferedBookings
{
    /** The identifier the reservation of an offer will have, so the offer can point at it before it exists. */
    public function nextReservationId(): string;

    /**
     * Book the offered space for the person, as the reservations module does (RF-11): the first free space of the collaborator that starts when the
     * offer does, with the price, the duration and the deadlines of today. With a voucher it is confirmed at once and a session is set aside (RF-28,
     * BO-04); without one, the barbershop decides: it is confirmed if it charges nothing (RE-07) and temporary otherwise, with until `$payUntil` to be
     * paid (LE-04). Whatever refuses the booking, such as an empty voucher, the same exception the booking throws, and nothing is left.
     */
    public function book(
        string $userId,
        string $serviceId,
        string $collaboratorId,
        DateTimeImmutable $startsAt,
        bool $withVoucher,
        string $reservationId,
        DateTimeImmutable $payUntil,
        DateTimeImmutable $now,
    ): void;

    /** Whether the reservation is temporary and its payment window is still open at the instant (RE-05). */
    public function isAwaitingPayment(string $reservationId, DateTimeImmutable $now): bool;
}
