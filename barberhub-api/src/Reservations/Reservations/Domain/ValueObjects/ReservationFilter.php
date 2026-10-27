<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Domain\ValueObjects;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;

/** What a listing of reservations narrows to; each criterion left empty does not narrow. */
final readonly class ReservationFilter
{
    /**
     * Keep the criteria: the customer role the reservations belong to, the collaborator whose agenda they are, their
     * status and the day they start, given as the instant the day begins in the time zone of the barbershop. The instant
     * `now` decides which temporary reservations are overdue, so they count as expired and not as temporary.
     */
    public function __construct(
        public ?CustomerMembershipId $customer = null,
        public ?CollaboratorId $collaborator = null,
        public ?ReservationStatus $status = null,
        public ?DateTimeImmutable $day = null,
        public ?DateTimeImmutable $now = null,
    ) {}

    /** The instants of the day asked for, from its beginning to the beginning of the next one, or none. */
    public function window(): ?TimeInterval
    {
        return $this->day === null ? null : TimeInterval::between($this->day, $this->day->modify('+1 day'));
    }
}
