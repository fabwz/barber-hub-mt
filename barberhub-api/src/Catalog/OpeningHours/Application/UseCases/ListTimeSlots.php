<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Application\UseCases;

use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;

final readonly class ListTimeSlots
{
    /** Receive the time slot repository port. */
    public function __construct(private TimeSlotRepository $slots) {}

    /**
     * List the weekly schedule of the current barbershop ordered by day and opening time.
     *
     * @return list<TimeSlot>
     */
    public function execute(): array
    {
        return $this->slots->all();
    }
}
