<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\Contracts;

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
}
