<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\Entities;

use DateTimeImmutable;
use LogicException;
use Src\Reservations\Reservations\Domain\Exceptions\RescheduleDeadlineExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\RescheduleLimitReachedException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CancellationClass;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatus;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationStatusChange;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Duration;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final class Reservation
{
    /**
     * Hours before the reservation within which it can be cancelled without penalty, for every barbershop until the
     * cancellation policies of #49 exist. Provisional: #49 replaces it with the value the barbershop defines (CA-01).
     */
    public const int DEFAULT_CANCELLATION_DEADLINE_HOURS = 24;

    /** Minutes of cleaning after the service, during which the collaborator cannot take another reservation (DI-01). */
    public const int CLEANUP_MINUTES = 5;

    /** Times a reservation can be moved (CA-05). */
    public const int MAX_RESCHEDULES = 2;

    /**
     * Hold the full state of a reservation.
     *
     * @param  list<ReservationStatusChange>  $pendingChanges  changes not saved yet; the repository stores them with the reservation
     */
    private function __construct(
        public readonly ReservationId $id,
        public private(set) CollaboratorId $collaboratorId,
        public readonly ServiceId $serviceId,
        public readonly CustomerMembershipId $customerMembershipId,
        public private(set) TimeInterval $interval,
        public private(set) DateTimeImmutable $blockedUntil,
        public readonly Money $price,
        public readonly Duration $duration,
        public readonly int $cancellationDeadlineHours,
        public private(set) int $rescheduleCount,
        public private(set) ReservationStatus $status,
        public private(set) array $pendingChanges,
    ) {}

    /**
     * Create a reservation. It starts temporary (RE-05); one that needs no payment is confirmed right after (RE-07).
     * It keeps the price, the duration and the cancellation deadline in force now (SV-03, CA-01), ends when the service
     * does and blocks the collaborator five minutes more for cleaning (DI-01). The creation is the first entry of its history.
     */
    public static function create(
        ReservationId $id,
        CollaboratorId $collaboratorId,
        ServiceId $serviceId,
        CustomerMembershipId $customerMembershipId,
        DateTimeImmutable $startsAt,
        Money $price,
        Duration $duration,
        int $cancellationDeadlineHours,
        Author $author,
        DateTimeImmutable $at,
    ): self {
        if ($price->colones <= 0) {
            throw new InvalidValueException('reservations.price_invalid');
        }

        if ($cancellationDeadlineHours < 0) {
            throw new InvalidValueException('reservations.cancellation_deadline_invalid');
        }

        $endsAt = $startsAt->modify("+{$duration->minutes} minutes");

        return new self(
            $id,
            $collaboratorId,
            $serviceId,
            $customerMembershipId,
            TimeInterval::between($startsAt, $endsAt),
            $endsAt->modify('+'.self::CLEANUP_MINUTES.' minutes'),
            $price,
            $duration,
            $cancellationDeadlineHours,
            0,
            ReservationStatus::Temporary,
            [new ReservationStatusChange(null, ReservationStatus::Temporary, $author, $at)],
        );
    }

    /** Rebuild a reservation from its stored state; its history is already saved, so nothing is pending. */
    public static function reconstitute(
        ReservationId $id,
        CollaboratorId $collaboratorId,
        ServiceId $serviceId,
        CustomerMembershipId $customerMembershipId,
        TimeInterval $interval,
        DateTimeImmutable $blockedUntil,
        Money $price,
        Duration $duration,
        int $cancellationDeadlineHours,
        int $rescheduleCount,
        ReservationStatus $status,
    ): self {
        return new self($id, $collaboratorId, $serviceId, $customerMembershipId, $interval, $blockedUntil, $price, $duration, $cancellationDeadlineHours, $rescheduleCount, $status, []);
    }

    /** Confirm a temporary reservation, once its payment is confirmed or when it needs none (RE-07). */
    public function confirm(DateTimeImmutable $at, Author $author): void
    {
        $this->changeTo(ReservationStatus::Confirmed, $at, $author);
    }

    /** Expire a temporary reservation whose payment did not arrive in time (RE-05); its space is released. */
    public function expire(DateTimeImmutable $at, Author $author): void
    {
        $this->changeTo(ReservationStatus::Expired, $at, $author);
    }

    /** Mark a confirmed reservation as attended. */
    public function complete(DateTimeImmutable $at, Author $author): void
    {
        $this->changeTo(ReservationStatus::Completed, $at, $author);
    }

    /** Mark a confirmed reservation as one the customer did not show up for. */
    public function markNoShow(DateTimeImmutable $at, Author $author): void
    {
        $this->changeTo(ReservationStatus::NoShow, $at, $author);
    }

    /** Cancel a temporary or confirmed reservation on behalf of the customer. */
    public function cancelByCustomer(DateTimeImmutable $at, Author $author): void
    {
        $this->changeTo(ReservationStatus::CancelledByCustomer, $at, $author);
    }

    /** Cancel a confirmed reservation on behalf of the barbershop. */
    public function cancelByBarbershop(DateTimeImmutable $at, Author $author): void
    {
        $this->changeTo(ReservationStatus::CancelledByBarbershop, $at, $author);
    }

    /**
     * Move a confirmed reservation to another start and, if the collaborator changes, to another collaborator (RF-15). The
     * price, the duration and the deadline are the ones copied when it was booked (SV-03), so the end and the cleaning
     * follow the new start. A customer can do it only up to the deadline before the ORIGINAL start; the owner is not bound
     * by it (CA-01, CA-07). At most twice (CA-05). The change leaves a confirmed-to-confirmed entry in the history with its
     * author and date. That the new space is free is not decided here: the caller checks it while it holds the collaborator.
     *
     * @throws TransitionNotAllowedException when the reservation is not confirmed
     * @throws RescheduleDeadlineExpiredException
     * @throws RescheduleLimitReachedException
     */
    public function reschedule(CollaboratorId $collaboratorId, DateTimeImmutable $startsAt, bool $byOwner, DateTimeImmutable $at, Author $author): void
    {
        if ($this->status !== ReservationStatus::Confirmed) {
            throw TransitionNotAllowedException::create();
        }

        if (! $byOwner && $at > $this->interval->start->modify("-{$this->cancellationDeadlineHours} hours")) {
            throw RescheduleDeadlineExpiredException::create();
        }

        if ($this->rescheduleCount >= self::MAX_RESCHEDULES) {
            throw RescheduleLimitReachedException::create();
        }

        $endsAt = $startsAt->modify("+{$this->duration->minutes} minutes");

        $this->collaboratorId = $collaboratorId;
        $this->interval = TimeInterval::between($startsAt, $endsAt);
        $this->blockedUntil = $endsAt->modify('+'.self::CLEANUP_MINUTES.' minutes');
        $this->rescheduleCount++;
        $this->pendingChanges[] = new ReservationStatusChange($this->status, $this->status, $author, $at);
    }

    /**
     * Classify the cancellation made at the given instant: the barbershop's is its own class and the customer's is on time
     * up to the deadline copied into the reservation, and late after it (CA-01, CA-04). It is computed, never stored.
     */
    public function classifyCancellation(DateTimeImmutable $cancelledAt): CancellationClass
    {
        return match ($this->status) {
            ReservationStatus::CancelledByBarbershop => CancellationClass::ByBarbershop,
            ReservationStatus::CancelledByCustomer => $cancelledAt <= $this->interval->start->modify("-{$this->cancellationDeadlineHours} hours")
                ? CancellationClass::OnTime
                : CancellationClass::Late,
            default => throw new LogicException('Only a cancelled reservation has a cancellation class.'),
        };
    }

    /** Tell whether the reservation was cancelled, by either side. */
    public function isCancelled(): bool
    {
        return $this->status === ReservationStatus::CancelledByCustomer || $this->status === ReservationStatus::CancelledByBarbershop;
    }

    /** Forget the pending changes once the repository stored them. */
    public function markSaved(): void
    {
        $this->pendingChanges = [];
    }

    /** Apply a change the status diagram allows and record it in the history (RE-04, RE-09). */
    private function changeTo(ReservationStatus $target, DateTimeImmutable $at, Author $author): void
    {
        if (! $this->status->canChangeTo($target)) {
            throw TransitionNotAllowedException::create();
        }

        $this->pendingChanges[] = new ReservationStatusChange($this->status, $target, $author, $at);
        $this->status = $target;
    }
}
