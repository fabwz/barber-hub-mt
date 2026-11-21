<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\UseCases;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\ServiceCatalog;
use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Application\DTOs\Availability;
use Src\Availability\Slots\Application\DTOs\DayAvailability;
use Src\Availability\Slots\Application\DTOs\StaffMemberName;
use Src\Availability\Slots\Application\DTOs\StaffSlots;
use Src\Availability\Slots\Domain\Exceptions\ServiceNotBookableException;
use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\ValueObjects\Duration;

final readonly class SearchAvailability
{
    public const int NEXT_DAYS = 3;

    /** Receive the ports that give the service and the names of the staff, the use case that lists the spaces of a day and the one that assigns "any available". */
    public function __construct(
        private ServiceCatalog $services,
        private StaffRoster $roster,
        private ListAvailableSlots $available,
        private AssignAnyAvailable $assignment,
    ) {}

    /**
     * Answer what a customer sees (RF-10): the service with its price, and the free spaces of a day grouped by
     * collaborator, of one or of all who offer the service. If the day has none, it adds the next 3 days that do,
     * looking up to the last day DI-03 allows and stopping when it finds them. A day outside that window has no spaces
     * and no next days. The waiting list option of RF-10 waits for that feature, which is deferred.
     * With `$anyAvailable` it shows one space per start, with the collaborator DI-04 assigns, grouped under that collaborator;
     * without it the answer is the same as always.
     * Reads: the service and the names once, then the four reads of a day (six with `$anyAvailable`) for each day it has to look at, at most 31.
     */
    public function execute(string $serviceId, string $date, ?string $collaboratorId, DateTimeImmutable $now, bool $anyAvailable = false): Availability
    {
        $service = $this->services->bookableService($serviceId) ?? throw ServiceNotBookableException::create();
        $day = CalendarDate::of($date);
        $staff = $this->roster->bookableNames($serviceId, $collaboratorId);
        $first = CalendarDate::fromMoment($now);
        $last = CalendarDate::fromMoment($now->modify('+'.SlotCalculator::WINDOW_DAYS.' days'));

        if ($staff === [] || $day->value < $first->value || $day->value > $last->value) {
            return new Availability($service, new DayAvailability($day->value, []), []);
        }

        $asked = $this->spacesOf($day, $service->duration, $serviceId, $collaboratorId, $staff, $now, $anyAvailable);
        $next = [];

        if ($asked->staff === []) {
            for ($candidate = $day->next(); $candidate->value <= $last->value && count($next) < self::NEXT_DAYS; $candidate = $candidate->next()) {
                $spaces = $this->spacesOf($candidate, $service->duration, $serviceId, $collaboratorId, $staff, $now, $anyAvailable);

                if ($spaces->staff !== []) {
                    $next[] = $spaces;
                }
            }
        }

        return new Availability($service, $asked, $next);
    }

    /**
     * The free spaces of a day grouped by collaborator, in the order of the staff; whoever has none is left out.
     *
     * @param  list<StaffMemberName>  $staff
     */
    private function spacesOf(CalendarDate $day, Duration $duration, string $serviceId, ?string $collaboratorId, array $staff, DateTimeImmutable $now, bool $anyAvailable): DayAvailability
    {
        $slots = $this->available->execute($serviceId, $day->value, $collaboratorId, $now, $duration);
        $slots = $anyAvailable ? $this->assignment->onePerStart($serviceId, $day, $slots, $now) : $slots;
        $groups = [];

        foreach ($staff as $member) {
            $own = array_values(array_filter($slots, fn (Slot $slot): bool => $slot->collaboratorId->value === $member->collaboratorId));

            if ($own !== []) {
                $groups[] = new StaffSlots($member->collaboratorId, $member->name, $own);
            }
        }

        return new DayAvailability($day->value, $groups);
    }
}
