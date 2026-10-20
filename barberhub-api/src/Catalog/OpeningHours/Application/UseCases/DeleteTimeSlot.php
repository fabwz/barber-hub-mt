<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Application\UseCases;

use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Exceptions\TimeSlotNotFoundException;
use Src\Catalog\OpeningHours\Domain\ValueObjects\TimeSlotId;

final readonly class DeleteTimeSlot
{
    /** Receive the time slot repository port. */
    public function __construct(private TimeSlotRepository $slots) {}

    /** Remove a slot from the schedule of the current barbershop. */
    public function execute(string $slotId): void
    {
        $slot = $this->slots->find(new TimeSlotId($slotId)) ?? throw TimeSlotNotFoundException::create();

        $this->slots->delete($slot);
    }
}
