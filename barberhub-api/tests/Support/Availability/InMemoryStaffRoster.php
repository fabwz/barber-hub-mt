<?php

declare(strict_types=1);

namespace Tests\Support\Availability;

use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Application\DTOs\StaffMemberName;
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

    /**
     * Give a name to everybody in the list, or only to the one asked for.
     *
     * @return list<StaffMemberName>
     */
    public function bookableNames(string $serviceId, ?string $collaboratorId): array
    {
        $members = array_map(fn (StaffWorkday $member): StaffMemberName => new StaffMemberName($member->collaboratorId, 'Barbero '.$member->collaboratorId), $this->staff);

        return array_values(array_filter($members, fn (StaffMemberName $member): bool => $collaboratorId === null || $member->collaboratorId === $collaboratorId));
    }
}
