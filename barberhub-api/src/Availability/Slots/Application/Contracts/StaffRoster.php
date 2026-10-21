<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

use Src\Availability\Slots\Application\DTOs\StaffMemberName;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;

interface StaffRoster
{
    /**
     * The active collaborators who offer a service and work on the day of the date, from the oldest to the newest (CO-01).
     *
     * @return list<StaffWorkday>
     */
    public function offering(string $serviceId, CalendarDate $date): array;

    /**
     * The names of the active collaborators who offer a service, or only of one when an identifier is given. An
     * identifier that is not a collaborator of the current barbershop fails as not found, and one that is inactive or
     * does not offer the service fails as an invalid value.
     *
     * @return list<StaffMemberName>
     */
    public function bookableNames(string $serviceId, ?string $collaboratorId): array;
}
