<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;

final readonly class IsAwaitingPayment
{
    /** Receive the reservation repository. */
    public function __construct(private ReservationRepository $reservations) {}

    /**
     * Whether a reservation of the current barbershop is temporary and its payment window is still open at the instant (RE-05): one that was paid,
     * cancelled, expired, whose window closed even if nobody marked it yet, or that does not exist, is not. The waitlist asks it to know whether the
     * person who accepts an offer again already has the reservation they must pay.
     */
    public function execute(string $reservationId, DateTimeImmutable $now): bool
    {
        $reservation = $this->reservations->find(new ReservationId($reservationId));

        return $reservation !== null && $reservation->status === ReservationStatus::Temporary && ! $reservation->isOverdueAt($now);
    }
}
