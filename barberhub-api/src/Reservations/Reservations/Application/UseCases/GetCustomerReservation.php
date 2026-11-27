<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\AttachNames;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\ReservationDeposits;
use Src\Reservations\Reservations\Application\DTOs\CancellationPreview;
use Src\Reservations\Reservations\Application\DTOs\ReservationDetail;
use Src\Reservations\Reservations\Application\QuotePayment;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Services\CancellationConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

final readonly class GetCustomerReservation
{
    /** Receive the reservation repository, the port that finds the customer role, the names attacher, the quote of what is owed, the deposits port and the table of consequences. */
    public function __construct(
        private ReservationRepository $reservations,
        private CustomerDirectory $customers,
        private AttachNames $attachNames,
        private QuotePayment $quote,
        private ReservationDeposits $deposits,
        private CancellationConsequence $consequences,
    ) {}

    /**
     * Show a customer one of their own reservations (RF-14). A temporary reservation past its payment window reads as expired (DI-05). It carries what is owed now (RF-20). Neither the balance (PA-10) nor the history, whose authors
     * are not for them, come with it. A confirmed reservation also carries what cancelling now would do to the deposit and the session (RF-17): the server computes it with the instant it is
     * given, so the customer knows before cancelling. A reservation of another customer reads like one that does not exist (UP-06).
     *
     * @throws ReservationNotFoundException
     */
    public function execute(string $userId, string $reservationId, DateTimeImmutable $now): ReservationDetail
    {
        $membershipId = $this->customers->find($userId);
        $reservation = $this->reservations->find(new ReservationId($reservationId));

        if ($membershipId === null || $reservation === null || $reservation->customerMembershipId?->value !== $membershipId) {
            throw ReservationNotFoundException::create();
        }

        $view = $this->attachNames->one($reservation, false, $now);

        return new ReservationDetail($view->withPayment($this->quote->one($reservation, $now)), null, null, $this->previewCancellation($reservation, $now));
    }

    /** What a cancellation made now would do; only a confirmed reservation has one. */
    private function previewCancellation(Reservation $reservation, DateTimeImmutable $now): ?CancellationPreview
    {
        if ($reservation->status !== ReservationStatus::Confirmed) {
            return null;
        }

        $class = $reservation->classifyCustomerCancellationAt($now);

        return new CancellationPreview(
            $class,
            $reservation->freeCancellationLimit(),
            $this->consequences->forCancellation($class, $this->deposits->isPaid($reservation->id->value), $reservation->purchasedVoucherId !== null),
        );
    }
}
