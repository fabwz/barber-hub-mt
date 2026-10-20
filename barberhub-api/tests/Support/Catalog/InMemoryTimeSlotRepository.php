<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Catalog\OpeningHours\Domain\ValueObjects\TimeSlotId;
use Src\Shared\Domain\ValueObjects\DayOfWeek;

final class InMemoryTimeSlotRepository implements TimeSlotRepository
{
    /** @var array<string, TimeSlot> */
    private array $slots = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): TimeSlotId
    {
        return new TimeSlotId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the slot. */
    public function save(TimeSlot $slot): void
    {
        $this->slots[$slot->id->value] = $slot;
    }

    /** Find a slot by its identity. */
    public function find(TimeSlotId $id): ?TimeSlot
    {
        return $this->slots[$id->value] ?? null;
    }

    /**
     * List the stored slots ordered by day and opening time.
     *
     * @return list<TimeSlot>
     */
    public function all(): array
    {
        $slots = array_values($this->slots);
        usort($slots, fn (TimeSlot $a, TimeSlot $b): int => [$a->day->value, $a->range->start->value] <=> [$b->day->value, $b->range->start->value]);

        return $slots;
    }

    /**
     * List the stored slots of one day ordered by opening time.
     *
     * @return list<TimeSlot>
     */
    public function ofDay(DayOfWeek $day): array
    {
        return array_values(array_filter($this->all(), fn (TimeSlot $slot): bool => $slot->day->equals($day)));
    }

    /** Remove the slot. */
    public function delete(TimeSlot $slot): void
    {
        unset($this->slots[$slot->id->value]);
    }
}
