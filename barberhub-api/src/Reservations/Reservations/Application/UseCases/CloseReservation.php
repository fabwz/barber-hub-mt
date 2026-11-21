<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use LogicException;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorDirectory;
use Src\Reservations\Reservations\Application\Contracts\ReservationDeposits;
use Src\Reservations\Reservations\Application\Contracts\ReservationVouchers;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotYetDueException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\Services\CancellationConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\SessionOutcome;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CloseReservation
{
    /** Receive the reservation repository, the port that finds the collaborator profile, the transaction port, the vouchers port, the deposits port and the table of consequences. */
    public function __construct(
        private ReservationRepository $reservations,
        private CollaboratorDirectory $collaborators,
        private TransactionRunner $transaction,
        private ReservationVouchers $vouchers,
        private ReservationDeposits $deposits,
        private CancellationConsequence $consequences,
    ) {}

    /**
     * Close a confirmed reservation as attended or as an absence (RF-18). A collaborator closes only the reservations of their
     * own agenda and the owner any of the barbershop; another collaborator's reservation reads like a missing one (CO-05, UP-06).
     * The rules of time live in the entity: attended only once the start has passed, an absence only after the tolerance
     * of 10 minutes (CO-05); a final status never changes (RE-04). The row is locked, so two simultaneous closings run in
     * turn. Closing again with the same outcome changes nothing: no entry in the history, no second use of the session (CA-06);
     * closing with the other outcome is a conflict, because the status is final. The status change leaves its entry with author
     * and date (RE-09). The consequence comes from the table of CancellationConsequence (RF-17): if a session of a voucher pays the reservation, the same
     * transaction leaves it used, attended or not (CA-03, BO-05). The deposit of an absence is retained (CA-03) and that needs no write: the
     * payment stays approved and not marked to give back, which is what retained means; an attended reservation applies it to the price.
     *
     * @param  ReservationStatus  $outcome  `Completed` or `NoShow`
     *
     * @throws ReservationNotFoundException
     * @throws TransitionNotAllowedException
     * @throws ReservationNotYetDueException
     */
    public function execute(string $userId, bool $isOwner, string $reservationId, ReservationStatus $outcome, DateTimeImmutable $now): Reservation
    {
        return $this->transaction->run(function () use ($userId, $isOwner, $reservationId, $outcome, $now): Reservation {
            $reservation = $this->reservations->findForUpdate(new ReservationId($reservationId));

            if ($reservation === null || (! $isOwner && $reservation->collaboratorId->value !== $this->collaborators->idOfUser($userId))) {
                throw ReservationNotFoundException::create();
            }

            if ($reservation->status === $outcome) {
                return $reservation;
            }

            $author = Author::person(new UserId($userId));

            match ($outcome) {
                ReservationStatus::Completed => $reservation->complete($now, $author),
                ReservationStatus::NoShow => $reservation->markNoShow($now, $author),
                default => throw new LogicException('A reservation is closed only as attended or as an absence.'),
            };

            $this->reservations->save($reservation);

            $paidWithVoucher = $reservation->purchasedVoucherId !== null;
            $consequence = $outcome === ReservationStatus::NoShow
                ? $this->consequences->forAbsence($this->deposits->isPaid($reservation->id->value), $paidWithVoucher)
                : $this->consequences->forAttended($paidWithVoucher);

            if ($reservation->purchasedVoucherId !== null && $consequence->session === SessionOutcome::Consumed) {
                $this->vouchers->consume($reservation->purchasedVoucherId->value, $reservation->id->value, $now);
            }

            return $reservation;
        });
    }
}
