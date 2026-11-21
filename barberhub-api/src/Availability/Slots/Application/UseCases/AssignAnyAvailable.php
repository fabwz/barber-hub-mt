<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\UseCases;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\DailyReservationCounts;
use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Domain\Services\AnyAvailableAssigner;
use Src\Availability\Slots\Domain\ValueObjects\AssignmentCandidate;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\Slot;

final readonly class AssignAnyAvailable
{
    /** Receive the use case that lists the free spaces, the ports for the staff and the counts of the day, and the strategy. */
    public function __construct(
        private ListAvailableSlots $available,
        private StaffRoster $roster,
        private DailyReservationCounts $counts,
        private AnyAvailableAssigner $assigner,
    ) {}

    /**
     * Turn the free spaces of all the staff on a day into one space per start, each with the collaborator DI-04 assigns:
     * among those free at that start, the one with the fewest reservations that day and, on a tie, the one registered
     * first. The staff comes from the roster, which lists only active collaborators who offer the service (CO-01), from
     * the oldest to the newest. It reads the roster and the counts once, whatever the number of spaces.
     *
     * @param  list<Slot>  $slots  The free spaces of the day of all who offer the service.
     * @return list<Slot> In order of start.
     */
    public function onePerStart(string $serviceId, CalendarDate $day, array $slots, DateTimeImmutable $now): array
    {
        if ($slots === []) {
            return [];
        }

        $ranks = array_flip(array_map(fn (StaffWorkday $member): string => $member->collaboratorId, $this->roster->offering($serviceId, $day)));
        $counts = $this->counts->between(array_keys($ranks), $day->window(), $now);
        $byStart = [];

        foreach ($slots as $slot) {
            $byStart[$slot->start->getTimestamp()][] = $slot;
        }

        ksort($byStart);
        $assigned = [];

        foreach ($byStart as $group) {
            $candidates = array_map(fn (Slot $slot): AssignmentCandidate => new AssignmentCandidate(
                $slot->collaboratorId,
                $counts[$slot->collaboratorId->value] ?? 0,
                $ranks[$slot->collaboratorId->value] ?? PHP_INT_MAX,
            ), $group);
            $chosen = $this->assigner->choose($candidates);
            $assigned[] = array_find($group, fn (Slot $slot): bool => $chosen !== null && $slot->collaboratorId->equals($chosen)) ?? $group[0];
        }

        return $assigned;
    }

    /**
     * The active collaborators who offer the service and work on the day of the start, whom the assignment may choose; the
     * caller locks them before it asks for the assignment.
     *
     * @return list<string>
     */
    public function candidates(string $serviceId, DateTimeImmutable $start): array
    {
        return array_map(
            fn (StaffWorkday $member): string => $member->collaboratorId,
            $this->roster->offering($serviceId, CalendarDate::fromMoment($start)),
        );
    }

    /**
     * The collaborator who would take the space that starts at the instant, or none if nobody is free then. It reads the
     * reservations again every time, so the caller must hold the locks of the candidates when it needs the answer to be final.
     */
    public function forStart(string $serviceId, DateTimeImmutable $start, DateTimeImmutable $now): ?string
    {
        $day = CalendarDate::fromMoment($start);
        $free = array_values(array_filter(
            $this->available->execute($serviceId, $day->value, null, $now),
            fn (Slot $slot): bool => $slot->start->getTimestamp() === $start->getTimestamp(),
        ));

        return ($this->onePerStart($serviceId, $day, $free, $now)[0] ?? null)?->collaboratorId->value;
    }
}
