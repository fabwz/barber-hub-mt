<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\UseCases;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\BarbershopHours;
use Src\Availability\Slots\Application\Contracts\BlockedIntervals;
use Src\Availability\Slots\Application\Contracts\ServiceCatalog;
use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Domain\Exceptions\ServiceNotBookableException;
use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\CollaboratorId;
use Src\Availability\Slots\Domain\ValueObjects\DayAgenda;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\ValueObjects\Duration;

final readonly class ListAvailableSlots
{
    /** Receive the ports that give the service, the opening hours, the staff and the reservations, and the calculator. */
    public function __construct(
        private ServiceCatalog $services,
        private BarbershopHours $hours,
        private StaffRoster $roster,
        private BlockedIntervals $blocked,
        private SlotCalculator $calculator,
    ) {}

    /**
     * List the free spaces for a service on a date, of one collaborator or of all who offer it, in order of start (RF-10).
     * It reads the service, the opening hours, the staff and the reservations once each, whatever the number of
     * collaborators, and stops early when the barbershop is closed or nobody works. "Any available" (DI-04) is the
     * assignment of the first of these lists to a collaborator, which belongs to #54. A caller that asks for several
     * days passes the duration it already read, so the service is not read again for each day. The reservation named in
     * `$ignoringReservationId` holds no space, so moving it a few minutes does not collide with itself (RE-08).
     *
     * @return list<Slot>
     */
    public function execute(string $serviceId, string $date, ?string $collaboratorId, DateTimeImmutable $now, ?Duration $known = null, ?string $ignoringReservationId = null): array
    {
        $duration = $known ?? $this->services->bookableDuration($serviceId) ?? throw ServiceNotBookableException::create();
        $day = CalendarDate::of($date);
        $open = $this->hours->openRangesOn($day);

        if ($open === []) {
            return [];
        }

        $staff = $this->roster->offering($serviceId, $day);

        if ($collaboratorId !== null) {
            $staff = array_values(array_filter($staff, fn (StaffWorkday $member): bool => $member->collaboratorId === $collaboratorId));
        }

        if ($staff === []) {
            return [];
        }

        $blocked = $this->blocked->between(array_map(fn (StaffWorkday $member): string => $member->collaboratorId, $staff), $day->window(), $now, $ignoringReservationId);
        $slots = [];

        foreach ($staff as $member) {
            $agenda = new DayAgenda(new CollaboratorId($member->collaboratorId), $day, $open, $member->shifts, $member->breaks, $blocked[$member->collaboratorId] ?? []);
            array_push($slots, ...$this->calculator->forAgenda($agenda, $duration, $now));
        }

        usort($slots, fn (Slot $a, Slot $b): int => $a->start <=> $b->start);

        return $slots;
    }
}
