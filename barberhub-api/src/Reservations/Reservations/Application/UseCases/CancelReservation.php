<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\ReservationVouchers;
use Src\Reservations\Reservations\Application\DTOs\CancelledReservation;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Shared\Domain\Contracts\AuditLog;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\AuditAction;
use Src\Shared\Domain\ValueObjects\AuditEntry;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CancelReservation
{
    /** Receive the reservation repository, the port that finds the customer role, the transaction port, the technical record and the vouchers port. */
    public function __construct(
        private ReservationRepository $reservations,
        private CustomerDirectory $customers,
        private TransactionRunner $transaction,
        private AuditLog $audit,
        private ReservationVouchers $vouchers,
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
     * the session is never given back twice (CA-06). The balance of the voucher is already in its append-only record, which names
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
                return new CancelledReservation($reservation, $reservation->classifyCancellation($this->cancelledAt($id, $reservation, $now)));
            }

            $author = Author::person(new UserId($userId));
            $previous = $reservation->status;
            $isOwner ? $reservation->cancelByBarbershop($now, $author) : $reservation->cancelByCustomer($now, $author);
            $this->reservations->save($reservation);

            if ($isOwner) {
                $this->recordCancellation($reservation, $previous, $userId, $now);
            }

            $class = $reservation->classifyCancellation($now);
            $this->settleVoucherSession($reservation, $class, $now);

            return new CancelledReservation($reservation, $class);
        });
    }

    /** Give the session of the voucher back, or leave it used when the cancellation was late (BO-05); a reservation with no voucher has nothing to settle. */
    private function settleVoucherSession(Reservation $reservation, CancellationClass $class, DateTimeImmutable $now): void
    {
        if ($reservation->purchasedVoucherId === null) {
            return;
        }

        $voucher = $reservation->purchasedVoucherId->value;

        $class === CancellationClass::Late
            ? $this->vouchers->consume($voucher, $reservation->id->value, $now)
            : $this->vouchers->release($voucher, $reservation->id->value, $now);
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
