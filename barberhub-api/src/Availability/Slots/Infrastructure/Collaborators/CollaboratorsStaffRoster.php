<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Collaborators;

use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Catalog\Collaborators\Application\DTOs\StaffWorkday as CollaboratorWorkday;
use Src\Catalog\Collaborators\Application\UseCases\ListStaffWorkingOn;

final readonly class CollaboratorsStaffRoster implements StaffRoster
{
    /** Receive the Collaborators use case that lists who offers a service and works on a day. */
    public function __construct(private ListStaffWorkingOn $listStaff) {}

    /**
     * List the collaborators who offer a service and work on the day of the date through the Collaborators module.
     *
     * @return list<StaffWorkday>
     */
    public function offering(string $serviceId, CalendarDate $date): array
    {
        return array_map(
            fn (CollaboratorWorkday $workday): StaffWorkday => new StaffWorkday($workday->collaboratorId, $workday->shifts, $workday->breaks),
            $this->listStaff->execute($serviceId, $date->dayOfWeek()),
        );
    }
}
