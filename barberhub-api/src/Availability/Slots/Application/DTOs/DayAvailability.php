<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\DTOs;

final readonly class DayAvailability
{
    /**
     * Carry the free spaces of a day grouped by collaborator; the list is empty when there are none.
     *
     * @param  list<StaffSlots>  $staff
     */
    public function __construct(
        public string $date,
        public array $staff,
    ) {}
}
