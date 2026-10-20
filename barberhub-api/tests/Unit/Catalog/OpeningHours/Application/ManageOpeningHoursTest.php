<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\OpeningHours\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Catalog\OpeningHours\Application\DTOs\AddTimeSlotCommand;
use Src\Catalog\OpeningHours\Application\UseCases\AddTimeSlot;
use Src\Catalog\OpeningHours\Application\UseCases\DeleteTimeSlot;
use Src\Catalog\OpeningHours\Application\UseCases\ListTimeSlots;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Catalog\OpeningHours\Domain\Exceptions\TimeSlotNotFoundException;
use Src\Catalog\OpeningHours\Domain\Exceptions\TimeSlotOverlapsException;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Catalog\InMemoryTimeSlotRepository;

/** The owner defines the weekly schedule in slots per day (RF-04, HO-01). */
final class ManageOpeningHoursTest extends TestCase
{
    private InMemoryTimeSlotRepository $slots;

    private AddTimeSlot $addTimeSlot;

    /** Wire the use cases to the in-memory repository. */
    protected function setUp(): void
    {
        $this->slots = new InMemoryTimeSlotRepository;
        $this->addTimeSlot = new AddTimeSlot($this->slots);
    }

    /** A slot is stored with its day and its two times. */
    public function test_slot_is_added(): void
    {
        $slot = $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));

        $this->assertSame(1, $slot->day->value);
        $this->assertSame(['09:00', '12:00'], [$slot->range->start->value, $slot->range->end->value]);
        $this->assertEquals($slot, $this->slots->find($slot->id));
    }

    /** A day can have more than one slot, as long as they do not overlap (HO-01). */
    public function test_a_day_takes_several_separate_slots(): void
    {
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '13:00', '18:00'));

        $this->assertCount(2, $this->slots->all());
    }

    /** Two slots that overlap on the same day are refused and the second one is not stored (RF-04). */
    public function test_overlapping_slots_of_a_day_are_refused(): void
    {
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));

        foreach ([['11:59', '14:00'], ['08:00', '09:01'], ['10:00', '11:00'], ['08:00', '18:00'], ['09:00', '12:00']] as [$opens, $closes]) {
            try {
                $this->addTimeSlot->execute(new AddTimeSlotCommand(1, $opens, $closes));
                $this->fail("The slot {$opens}-{$closes} was accepted.");
            } catch (TimeSlotOverlapsException $exception) {
                $this->assertSame('opening_hours.time_slot_overlaps', $exception->translationKey());
            }
        }

        $this->assertCount(1, $this->slots->all());
    }

    /** Slots that only touch, 09:00-12:00 and 12:00-15:00, are allowed: the closing is excluded. */
    public function test_contiguous_slots_are_allowed(): void
    {
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '12:00', '15:00'));

        $this->assertCount(2, $this->slots->all());
    }

    /** The same hours on another day do not overlap. */
    public function test_the_same_hours_on_another_day_do_not_overlap(): void
    {
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));
        $this->addTimeSlot->execute(new AddTimeSlotCommand(2, '09:00', '12:00'));

        $this->assertCount(2, $this->slots->all());
    }

    /**
     * Commands that break a rule of the value objects, and the translation key reported.
     *
     * @return array<string, array{int, string, string, string}>
     */
    public static function invalidCommands(): array
    {
        return [
            'day 0' => [0, '09:00', '12:00', 'shared.day_of_week_out_of_range'],
            'day 8' => [8, '09:00', '12:00', 'shared.day_of_week_out_of_range'],
            'hour 24' => [1, '24:00', '25:00', 'shared.time_of_day_invalid'],
            'text instead of a time' => [1, '09:00', 'tarde', 'shared.time_of_day_invalid'],
            'closing equal to the opening' => [1, '09:00', '09:00', 'shared.time_range_start_not_before_end'],
            'closing before the opening' => [1, '12:00', '09:00', 'shared.time_range_start_not_before_end'],
        ];
    }

    /** Invalid data is refused and nothing is stored. */
    #[DataProvider('invalidCommands')]
    public function test_invalid_data_is_refused_and_nothing_is_stored(int $day, string $opens, string $closes, string $key): void
    {
        try {
            $this->addTimeSlot->execute(new AddTimeSlotCommand($day, $opens, $closes));
            $this->fail('Invalid data was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame($key, $exception->translationKey());
            $this->assertSame([], $this->slots->all());
        }
    }

    /** The schedule lists every slot ordered by day and opening time. */
    public function test_schedule_is_listed_by_day_and_opening_time(): void
    {
        $this->addTimeSlot->execute(new AddTimeSlotCommand(2, '09:00', '12:00'));
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '13:00', '18:00'));
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '08:00', '12:00'));

        $listed = array_map(
            fn (TimeSlot $slot): string => "{$slot->day->value} {$slot->range->start->value}",
            new ListTimeSlots($this->slots)->execute(),
        );

        $this->assertSame(['1 08:00', '1 13:00', '2 09:00'], $listed);
    }

    /** A removed slot frees its hours, and removing one that does not exist is a not-found error. */
    public function test_slot_is_deleted_and_its_hours_are_free_again(): void
    {
        $slot = $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '09:00', '12:00'));
        $delete = new DeleteTimeSlot($this->slots);

        $delete->execute($slot->id->value);

        $this->assertSame([], $this->slots->all());
        $this->addTimeSlot->execute(new AddTimeSlotCommand(1, '10:00', '11:00'));

        $this->expectException(TimeSlotNotFoundException::class);
        $delete->execute($slot->id->value);
    }
}
