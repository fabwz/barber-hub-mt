<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\UseCases\CreateReservation;
use Src\Reservations\Reservations\Application\UseCases\IsAwaitingPayment;
use Src\Waitlist\Offers\Application\Contracts\OfferedBookings;

final readonly class ReservationsOfferedBookings implements OfferedBookings
{
    /** Receive the Reservations use cases that book a space and tell whether a reservation waits for its payment. */
    public function __construct(
        private CreateReservation $createReservation,
        private IsAwaitingPayment $awaitingPayment,
    ) {}

    /** A time-ordered UUID for the reservation of an offer. */
    public function nextReservationId(): string
    {
        return (string) Str::uuid7();
    }

    /** Book the space through the Reservations module, in the time zone of the barbershop, as any booking of a customer. */
    public function book(
        string $userId,
        string $serviceId,
        string $collaboratorId,
        DateTimeImmutable $startsAt,
        bool $withVoucher,
        string $reservationId,
        DateTimeImmutable $payUntil,
        DateTimeImmutable $now,
    ): void {
        $this->createReservation->execute(
            new CreateReservationCommand(
                $userId,
                $serviceId,
                $collaboratorId,
                $startsAt->setTimezone(new DateTimeZone((string) config('app.timezone'))),
                $withVoucher,
                $reservationId,
                $payUntil,
            ),
            $now,
        );
    }

    /** Ask the Reservations module whether the reservation is temporary and still inside its payment window. */
    public function isAwaitingPayment(string $reservationId, DateTimeImmutable $now): bool
    {
        return $this->awaitingPayment->execute($reservationId, $now);
    }
}
