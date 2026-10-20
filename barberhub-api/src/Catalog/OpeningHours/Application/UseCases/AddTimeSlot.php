<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Application\UseCases;

use Src\Catalog\OpeningHours\Application\DTOs\AddTimeSlotCommand;
use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Catalog\OpeningHours\Domain\Exceptions\TimeSlotOverlapsException;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;

final readonly class AddTimeSlot
{
    /** Receive the time slot repository port. */
    public function __construct(private TimeSlotRepository $slots) {}

    /** Add a slot to the schedule of the current barbershop unless it overlaps another of the same day (HO-01). */
    public function execute(AddTimeSlotCommand $command): TimeSlot
    {
        $slot = TimeSlot::create(
            $this->slots->nextId(),
            new DayOfWeek($command->day),
            TimeRange::between(new TimeOfDay($command->opensAt), new TimeOfDay($command->closesAt)),
        );

        if (array_any($this->slots->ofDay($slot->day), $slot->overlaps(...))) {
            throw TimeSlotOverlapsException::create();
        }

        $this->slots->save($slot);

        return $slot;
    }
}
