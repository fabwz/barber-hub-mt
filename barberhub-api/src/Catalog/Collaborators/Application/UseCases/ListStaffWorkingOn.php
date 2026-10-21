<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\DTOs\StaffWorkday;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\ValueObjects\BreakPeriod;
use Src\Catalog\Collaborators\Domain\ValueObjects\OfferedServiceId;
use Src\Catalog\Collaborators\Domain\ValueObjects\Shift;
use Src\Shared\Domain\ValueObjects\DayOfWeek;

final readonly class ListStaffWorkingOn
{
    /** Receive the collaborator repository port. */
    public function __construct(private CollaboratorRepository $collaborators) {}

    /**
     * List the shifts and breaks of the active collaborators who offer a service and work on a day of the week, from the
     * oldest to the newest; the availability reads it (CO-01). The repository loads all of them with a fixed number of queries.
     *
     * @return list<StaffWorkday>
     */
    public function execute(string $serviceId, DayOfWeek $day): array
    {
        $workdays = array_map(fn (Collaborator $collaborator): StaffWorkday => new StaffWorkday(
            $collaborator->id->value,
            array_values(array_map(
                fn (Shift $shift) => $shift->range,
                array_filter($collaborator->schedule->shifts, fn (Shift $shift): bool => $shift->day->equals($day)),
            )),
            array_values(array_map(
                fn (BreakPeriod $break) => $break->range,
                array_filter($collaborator->schedule->breaks, fn (BreakPeriod $break): bool => $break->day->equals($day)),
            )),
        ), $this->collaborators->activeOffering(new OfferedServiceId($serviceId)));

        return array_values(array_filter($workdays, fn (StaffWorkday $workday): bool => $workday->shifts !== []));
    }
}
