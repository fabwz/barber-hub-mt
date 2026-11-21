<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\ReservationDeposits;
use Src\Reservations\Reservations\Application\Contracts\ReservationVouchers;
use Src\Reservations\Reservations\Application\DTOs\CancelledReservation;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\Services\CancellationConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\DepositOutcome;
use Src\Reservations\Reservations\Domain\ValueObjects\EconomicConsequence;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\SessionOutcome;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CancelReservation
{
    /** Receive the reservation repository, the port that finds the customer role, the transaction port, the technical record, the vouchers port, the deposits port, the table of consequences, the events of the domain and the barbershop of the operation. */
    public function __construct(
        private ReservationRepository $reservations,
        private CustomerDirectory $customers,
        private TransactionRunner $transaction,
        private AuditLog $audit,
        private ReservationVouchers $vouchers,
        private ReservationDeposits $deposits,
        private CancellationConsequence $consequences,
        private DomainEvents $events,
        private BarbershopContext $context,
    ) {}

    /**
     * Cancel a reservation and free its space in the same transaction (RF-16, CA-07): the exclusions of the table cover
     * only the temporary and confirmed statuses, so the new status releases the space. A customer cancels only their own,
     * the owner any of the barbershop; another customer's reads like a missing one (UP-06). The row is locked, so two
     * simultaneous cancellations run in turn and the second finds it cancelled. Cancelling again changes nothing and adds
     * no entry to the history (CA-06); a repeat keeps the class of the first one, measured at the instant it was made.
     * A reservation in a final status that is not a cancellation cannot be cancelled (RE-04). A cancellation by the barbershop
     * leaves its entry in the technical record, in the same transaction (SE-04); the cancellation by the customer does not. If a
     * session of a voucher pays the reservation, the same transaction settles it: an on-time cancellation, or the one of the
     * barbershop, gives the session back and a late one leaves it used (RF-17, BO-05, CA-02, CA-03). A repeat returns before it, so
     * the session is never given back twice (CA-06). The deposit paid online is the other half of the consequence (RF-17, table of CancellationConsequence): an on-time cancellation, or the one of the barbershop, marks the approved payment as one to give back, once and without locking it (CA-02, CA-04, CA-06); a late one leaves it approved, which is retained (CA-03). A repeat only reports the consequence and never marks again. The balance of the voucher is already in its append-only record, which names
     * the reservation, so the technical record needs no further entry for it (SE-04 names balance adjustments, which do not exist).
     *
     * @throws ReservationNotFoundException
     * @throws TransitionNotAllowedException
     */
    public function execute(string $userId, bool $isOwner, string $reservationId, DateTimeImmutable $now): CancelledReservation
    {
        return $this->transaction->run(function () use ($userId, $isOwner, $reservationId, $now): CancelledReservation {
            $id = new ReservationId($reservationId);
            $reservation = $this->reservations->findForUpdate($id);

            if ($reservation === null || (! $isOwner && ! $this->belongsTo($reservation, $userId))) {
                throw ReservationNotFoundException::create();
            }

            if ($reservation->isCancelled()) {
                $class = $reservation->classifyCancellation($this->cancelledAt($id, $reservation, $now));

                return new CancelledReservation($reservation, $class, $this->consequences->forCancellation($class, $this->deposits->isPaid($reservation->id->value), $reservation->purchasedVoucherId !== null));
            }

            $author = Author::person(new UserId($userId));
            $previous = $reservation->status;
            $isOwner ? $reservation->cancelByBarbershop($now, $author) : $reservation->cancelByCustomer($now, $author);
            $this->reservations->save($reservation);

            if ($isOwner) {
                $this->recordCancellation($reservation, $previous, $userId, $now);
            }

            $class = $reservation->classifyCancellation($now);
            $consequence = $this->consequences->forCancellation($class, $this->deposits->isPaid($reservation->id->value), $reservation->purchasedVoucherId !== null);
            $this->apply($reservation, $consequence, $now);
            $this->events->publish(new SpaceReleased(
                $this->context->current()->value,
                $reservation->id->value,
                $reservation->collaboratorId->value,
                $reservation->interval->start,
                $reservation->blockedUntil,
            ));

            return new CancelledReservation($reservation, $class, $consequence);
        });
    }

    /** Apply the consequence: mark the deposit to give back, and give the session of the voucher back or leave it used (CA-02, CA-03, BO-05). Retained and none need no write. */
    private function apply(Reservation $reservation, EconomicConsequence $consequence, DateTimeImmutable $now): void
    {
        if ($consequence->deposit === DepositOutcome::ToRefund) {
            $this->deposits->markToRefund($reservation->id->value, $now);
        }

        if ($reservation->purchasedVoucherId === null) {
            return;
        }

        $voucher = $reservation->purchasedVoucherId->value;

        match ($consequence->session) {
            SessionOutcome::Consumed => $this->vouchers->consume($voucher, $reservation->id->value, $now),
            SessionOutcome::Released => $this->vouchers->release($voucher, $reservation->id->value, $now),
            SessionOutcome::None => null,
        };
    }

    /** Leave the status change of a cancellation by the barbershop in the technical record (SE-04). */
    private function recordCancellation(Reservation $reservation, ReservationStatus $previous, string $userId, DateTimeImmutable $now): void
    {
        $entry = AuditEntry::of(
            new UserId($userId),
            AuditAction::ReservationCancelledByBarbershop,
            'reservation',
            $reservation->id->value,
            ['status' => $previous->value],
            ['status' => $reservation->status->value],
            $now,
        );

        if ($entry !== null) {
            $this->audit->record($entry);
        }
    }

    /** Tell whether the reservation is the customer's own. */
    private function belongsTo(Reservation $reservation, string $userId): bool
    {
        $membershipId = $this->customers->find($userId);

        return $membershipId !== null && $reservation->customerMembershipId->value === $membershipId;
    }

    /** The instant of the entry that cancelled the reservation, or now if the history has none. */
    private function cancelledAt(ReservationId $id, Reservation $reservation, DateTimeImmutable $now): DateTimeImmutable
    {
        foreach (array_reverse($this->reservations->history($id)) as $change) {
            if ($change->to === $reservation->status) {
                return $change->at;
            }
        }

        return $now;
    }
}
