<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Reservations;

use DateTimeImmutable;
use Src\Payments\Payments\Application\Contracts\PayableReservations;
use Src\Payments\Payments\Application\DTOs\PayableReservation;
use Src\Payments\Payments\Domain\Exceptions\PaymentNotFoundException;
use Src\Reservations\Reservations\Application\UseCases\GetCustomerReservation;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class ReservationsPayableReservations implements PayableReservations
{
    /** Receive the Reservations use case that shows a customer their own reservation. */
    public function __construct(private GetCustomerReservation $getCustomerReservation) {}

    /** Read the reservation through Reservations: one of another customer or barbershop reads like one that does not exist. */
    public function ofCustomer(string $userId, string $reservationId, DateTimeImmutable $now): PayableReservation
    {
        try {
            $view = $this->getCustomerReservation->execute($userId, $reservationId, $now)->view;
        } catch (ReservationNotFoundException) {
            throw PaymentNotFoundException::create();
        }

        return new PayableReservation(
            $view->reservation->id->value,
            Money::of($view->reservation->price->colones),
            $view->status === ReservationStatus::Temporary,
        );
    }
}
