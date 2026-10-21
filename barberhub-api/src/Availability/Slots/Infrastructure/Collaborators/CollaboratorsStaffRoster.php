<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Collaborators;

use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Application\DTOs\StaffMemberName;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Catalog\Collaborators\Application\DTOs\BookableStaffMember;
use Src\Catalog\Collaborators\Application\DTOs\StaffWorkday as CollaboratorWorkday;
use Src\Catalog\Collaborators\Application\UseCases\ListBookableStaff;
use Src\Catalog\Collaborators\Application\UseCases\ListStaffWorkingOn;

final readonly class CollaboratorsStaffRoster implements StaffRoster
{
    /** Receive the Collaborators use cases that list who offers a service, who works on a day and who can be booked by name. */
    public function __construct(
        private ListStaffWorkingOn $listStaff,
        private ListBookableStaff $listBookable,
    ) {}

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

    /**
     * Give the names of the collaborators who can be booked for a service through the Collaborators module.
     *
     * @return list<StaffMemberName>
     */
    public function bookableNames(string $serviceId, ?string $collaboratorId): array
    {
        return array_map(
            fn (BookableStaffMember $member): StaffMemberName => new StaffMemberName($member->collaboratorId, $member->name),
            $this->listBookable->execute($serviceId, $collaboratorId),
        );
    }
}
