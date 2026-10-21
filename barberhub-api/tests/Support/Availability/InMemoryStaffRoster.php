<?php

declare(strict_types=1);

namespace Tests\Support\Availability;

use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Application\DTOs\StaffWorkday;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;

final class InMemoryStaffRoster implements StaffRoster
{
    public int $reads = 0;

    /** @param list<StaffWorkday> $staff Everybody who offers the service and works that day. */
    public function __construct(private readonly array $staff) {}

    /**
     * List the staff and count the read.
     *
     * @return list<StaffWorkday>
     */
    public function offering(string $serviceId, CalendarDate $date): array
    {
        $this->reads++;

        return $this->staff;
    }
}
