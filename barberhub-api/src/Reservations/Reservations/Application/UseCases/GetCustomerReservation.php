<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\DTOs\ReservationDetail;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;

final readonly class GetCustomerReservation
{
    /** Receive the reservation repository, the port that finds the customer role and the names attacher. */
    public function __construct(
        private ReservationRepository $reservations,
        private CustomerDirectory $customers,
        private AttachNames $attachNames,
    ) {}

    /**
     * Show a customer one of their own reservations (RF-14). Neither the balance (PA-10) nor the history, whose authors
     * are not for them, come with it. A reservation of another customer reads like one that does not exist (UP-06).
     *
     * @throws ReservationNotFoundException
     */
    public function execute(string $userId, string $reservationId): ReservationDetail
    {
        $membershipId = $this->customers->find($userId);
        $reservation = $this->reservations->find(new ReservationId($reservationId));

        if ($membershipId === null || $reservation === null || $reservation->customerMembershipId->value !== $membershipId) {
            throw ReservationNotFoundException::create();
        }

        return new ReservationDetail($this->attachNames->one($reservation, false), null, null);
    }
}
