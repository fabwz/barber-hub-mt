<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Domain\Contracts;

use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Catalog\OpeningHours\Domain\Exceptions\TimeSlotOverlapsException;
use Src\Catalog\OpeningHours\Domain\ValueObjects\TimeSlotId;
use Src\Shared\Domain\ValueObjects\DayOfWeek;

interface TimeSlotRepository
{
    /** Generate the identity for a new slot. */
    public function nextId(): TimeSlotId;

    /**
     * Persist a new slot in the current barbershop.
     *
     * @throws TimeSlotOverlapsException
     */
    public function save(TimeSlot $slot): void;

    /** Find a slot of the current barbershop. */
    public function find(TimeSlotId $id): ?TimeSlot;

    /**
     * List every slot of the current barbershop ordered by day and opening time.
     *
     * @return list<TimeSlot>
     */
    public function all(): array;

    /**
     * List the slots of the current barbershop on one day of the week, ordered by opening time.
     *
     * @return list<TimeSlot>
     */
    public function ofDay(DayOfWeek $day): array;

    /** Remove a slot from the schedule. */
    public function delete(TimeSlot $slot): void;
}
