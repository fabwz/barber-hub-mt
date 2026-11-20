<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\BlockedIntervals;
use Src\Availability\Slots\Infrastructure\Reservations\ReservationsBlockedIntervals;
use Src\Availability\Slots\Infrastructure\Waitlist\OffersBlockedIntervals;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class ReservationsAndOffersBlockedIntervals implements BlockedIntervals
{
    /** Receive the two sources of held time: the reservations and the offers of the waitlist. */
    public function __construct(
        private ReservationsBlockedIntervals $reservations,
        private OffersBlockedIntervals $offers,
    ) {}

    /**
     * What some collaborators hold in a window: the time of their temporary and confirmed reservations plus the whole space of their pending
     * offers, so a space offered to a person does not appear free to anybody else, in the calculation of the availability, in the booking and
     * in the rescheduling alike (DI-05). The reservation named is left out of the reservations; an offer is never the reservation being moved.
     * Intervals may overlap, as a reservation does not know about an offer, and come out ordered by start.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
    {
        $held = $this->reservations->between($collaboratorIds, $window, $now, $ignoringReservationId);

        foreach ($this->offers->between($collaboratorIds, $window, $now) as $collaboratorId => $spaces) {
            $all = [...($held[$collaboratorId] ?? []), ...$spaces];
            usort($all, fn (TimeInterval $a, TimeInterval $b): int => $a->start <=> $b->start);
            $held[$collaboratorId] = $all;
        }

        return $held;
    }
}
