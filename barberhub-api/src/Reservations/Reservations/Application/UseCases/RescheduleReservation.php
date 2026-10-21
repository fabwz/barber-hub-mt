<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerDirectory;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\RescheduleDeadlineExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\RescheduleLimitReachedException;
use Src\Reservations\Reservations\Domain\Exceptions\ReservationNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class RescheduleReservation
{
    /** Receive the reservation repository, the ports to the neighbouring modules and the transaction port. */
    public function __construct(
        private ReservationRepository $reservations,
        private CustomerDirectory $customers,
        private CollaboratorLock $collaborators,
        private FreeSlots $slots,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Move a confirmed reservation to another start and, optionally, another collaborator (RF-15). It is atomic (RE-08): in
     * one transaction it locks the reservation, applies the rules of the entity (status, deadline of the customer, limit of
     * two), locks the collaborator of the destination, checks again that the new space is free by the same calculation as
     * the booking, leaving the reservation itself out because it cannot collide with itself, and moves it. If the space is
     * taken, by the check or by the exclusion of the database (the second barrier, ADR 0009), nothing changes and the error
     * carries up to 3 alternatives, read after the rollback (RE-02). Freeing the old space is the same update: the exclusion
     * covers only the new one. A customer moves only their own; the owner any of the barbershop, with no deadline (CA-01,
     * CA-07); another customer's reads like a missing one (UP-06). The deposit is not charged again (CA-05) and the
     * notice to the customer when the owner moves it (CA-08) is #96; neither exists yet.
     *
     * @throws ReservationNotFoundException
     * @throws TransitionNotAllowedException
     * @throws RescheduleDeadlineExpiredException
     * @throws RescheduleLimitReachedException
     * @throws SlotUnavailableException
     * @throws OverlappingReservationException
     */
    public function execute(string $userId, bool $isOwner, string $reservationId, DateTimeImmutable $startsAt, ?string $collaboratorId, DateTimeImmutable $now): Reservation
    {
        $id = new ReservationId($reservationId);
        $service = null;
        $target = $collaboratorId;

        try {
            return $this->transaction->run(function () use ($userId, $isOwner, $id, $startsAt, $collaboratorId, $now, &$service, &$target): Reservation {
                $reservation = $this->reservations->findForUpdate($id);

                if ($reservation === null || (! $isOwner && ! $this->belongsTo($reservation, $userId))) {
                    throw ReservationNotFoundException::create();
                }

                $service = $reservation->serviceId->value;
                $target = $collaboratorId ?? $reservation->collaboratorId->value;

                $reservation->reschedule(new CollaboratorId($target), $startsAt, $isOwner, $now, Author::person(new UserId($userId)));
                $this->collaborators->lock($target);

                if (! $this->slots->isFree($service, $target, $startsAt, $now, $id->value)) {
                    throw SlotUnavailableException::create();
                }

                if ($this->reservations->hasConfirmedOverlapping($reservation->customerMembershipId, $reservation->interval, $id)) {
                    throw OverlappingReservationException::create();
                }

                $this->reservations->save($reservation);

                return $reservation;
            });
        } catch (SlotUnavailableException $exception) {
            throw $exception->withAlternatives($this->slots->alternatives((string) $service, (string) $target, $startsAt, $now, $id->value));
        }
    }

    /** Tell whether the reservation is the customer's own. */
    private function belongsTo(Reservation $reservation, string $userId): bool
    {
        $membershipId = $this->customers->find($userId);

        return $membershipId !== null && $reservation->customerMembershipId->value === $membershipId;
    }
}
