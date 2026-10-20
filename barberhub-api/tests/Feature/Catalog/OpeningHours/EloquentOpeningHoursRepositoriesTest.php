<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\OpeningHours;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Closures\Domain\Entities\Closure;
use Src\Catalog\Closures\Domain\Exceptions\ClosureAlreadyExistsException;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureDate;
use Src\Catalog\Closures\Domain\ValueObjects\ClosureReason;
use Src\Catalog\Closures\Infrastructure\Persistence\Repositories\EloquentClosureRepository;
use Src\Catalog\OpeningHours\Application\Contracts\ClosedDates;
use Src\Catalog\OpeningHours\Application\UseCases\ListOpenRangesOnDate;
use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Domain\Entities\TimeSlot;
use Src\Catalog\OpeningHours\Domain\Exceptions\TimeSlotOverlapsException;
use Src\Catalog\OpeningHours\Domain\ValueObjects\TimeSlotId;
use Src\Catalog\OpeningHours\Infrastructure\Closures\ClosuresClosedDates;
use Src\Catalog\OpeningHours\Infrastructure\Persistence\Models\TimeSlotModel;
use Src\Catalog\OpeningHours\Infrastructure\Persistence\Repositories\EloquentTimeSlotRepository;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\DayOfWeek;
use Src\Shared\Domain\ValueObjects\TimeOfDay;
use Src\Shared\Domain\ValueObjects\TimeRange;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class EloquentOpeningHoursRepositoriesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string PURA_NAVAJA = '0192f0c4-0000-7000-8000-00000000b001';

    private const string FADE_LAB = '0192f0c4-0000-7000-8000-00000000b002';

    private EloquentTimeSlotRepository $slots;

    private EloquentClosureRepository $closures;

    /** Two barbershops; the repositories work for the first and the queries of the test reach it. */
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::PURA_NAVAJA => 'pura-navaja', self::FADE_LAB => 'fade-lab'] as $id => $link) {
            DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);
        }

        $this->slots = new EloquentTimeSlotRepository($this->contextOf(self::PURA_NAVAJA));
        $this->closures = new EloquentClosureRepository($this->contextOf(self::PURA_NAVAJA));
        $this->scopeQueriesTo(self::PURA_NAVAJA);
    }

    /** A saved slot keeps its barbershop, day and hours, and is read back as the same entity. */
    public function test_slot_is_saved_in_the_current_barbershop_and_read_back(): void
    {
        $slot = $this->slot(1, '09:00', '12:00');

        $this->slots->save($slot);

        $model = TimeSlotModel::query()->findOrFail($slot->id->value);
        $this->assertSame([self::PURA_NAVAJA, 1], [$model->barbershop_id, $model->day_of_week]);
        $this->assertEquals($slot, $this->slots->find($slot->id));
        $this->assertNull($this->slots->find(new TimeSlotId((string) Str::uuid7())));
    }

    /** The slots come ordered by day and opening time, and one day brings only its own. */
    public function test_slots_are_listed_by_day_and_opening_time(): void
    {
        $this->slots->save($tuesday = $this->slot(2, '09:00', '12:00'));
        $this->slots->save($afternoon = $this->slot(1, '13:00', '18:00'));
        $this->slots->save($morning = $this->slot(1, '09:00', '12:00'));

        $this->assertEquals([$morning, $afternoon, $tuesday], $this->slots->all());
        $this->assertEquals([$morning, $afternoon], $this->slots->ofDay(new DayOfWeek(1)));
        $this->assertSame([], $this->slots->ofDay(new DayOfWeek(7)));
    }

    /** A deleted slot is gone. */
    public function test_slot_is_deleted(): void
    {
        $slot = $this->slot(1, '09:00', '12:00');
        $this->slots->save($slot);

        $this->slots->delete($slot);

        $this->assertNull($this->slots->find($slot->id));
    }

    /**
     * The second barrier (ADR 0009): a slot that overlaps another, saved without the check of the use case, is refused by
     * the exclusion of the database and the repository translates it to the exception of the domain.
     */
    public function test_the_exclusion_violation_becomes_the_overlap_exception(): void
    {
        $this->slots->save($this->slot(1, '09:00', '12:00'));

        try {
            $this->slots->save($this->slot(1, '11:00', '14:00'));
            $this->fail('An overlapping slot was saved.');
        } catch (TimeSlotOverlapsException $exception) {
            $this->assertSame('opening_hours.time_slot_overlaps', $exception->translationKey());
        }

        $this->assertCount(1, $this->slots->all());
    }

    /** The repository is usable after the translated violation, even inside a transaction: the savepoint keeps it alive. */
    public function test_the_transaction_survives_the_translated_violation(): void
    {
        $this->slots->save($this->slot(1, '09:00', '12:00'));

        DB::transaction(function (): void {
            try {
                $this->slots->save($this->slot(1, '10:00', '11:00'));
            } catch (TimeSlotOverlapsException) {
                // Expected.
            }

            $this->slots->save($this->slot(1, '12:00', '15:00'));
        });

        $this->assertCount(2, $this->slots->all());
    }

    /** The repository of one barbershop neither finds nor lists the slots of another, and the same hours may be saved in both. */
    public function test_slots_do_not_reach_another_barbershop(): void
    {
        $other = $this->slot(1, '09:00', '12:00');
        $this->saveSlotFor(self::FADE_LAB, $other);
        $own = $this->slot(1, '09:00', '12:00');
        $this->slots->save($own);

        $this->assertNull($this->slots->find($other->id));
        $this->assertEquals([$own], $this->slots->all());
    }

    /** The filter is a barrier of its own: with the database scoped to Fade Lab, the repository of Pura Navaja still reaches none of its slots. */
    public function test_slot_filter_holds_when_the_policy_lets_the_rows_through(): void
    {
        $other = $this->slot(1, '09:00', '12:00');
        $this->saveSlotFor(self::FADE_LAB, $other);
        $this->scopeQueriesTo(self::FADE_LAB);

        $this->assertSame(1, DB::table('opening_hours')->count());
        $this->assertNull($this->slots->find($other->id));
        $this->assertSame([], $this->slots->all());
        $this->assertSame([], $this->slots->ofDay(new DayOfWeek(1)));

        $this->slots->delete($other);
        $this->assertSame(1, DB::table('opening_hours')->count());
    }

    /** A saved closure keeps its date and reason, and is read back as the same entity. */
    public function test_closure_is_saved_and_read_back(): void
    {
        $closure = $this->closure('2026-12-25', 'Navidad');

        $this->closures->save($closure);

        $this->assertEquals($closure, $this->closures->find($closure->id));
        $this->assertEquals($closure, $this->closures->onDate(new ClosureDate('2026-12-25')));
        $this->assertNull($this->closures->onDate(new ClosureDate('2026-12-26')));
    }

    /** The closures come from a date onward, ordered by date. */
    public function test_closures_are_listed_from_a_date_by_date(): void
    {
        $this->closures->save($christmas = $this->closure('2026-12-25'));
        $this->closures->save($newYear = $this->closure('2027-01-01'));
        $this->closures->save($this->closure('2026-10-01'));

        $this->assertEquals([$christmas, $newYear], $this->closures->from(new ClosureDate('2026-10-20')));
    }

    /** The unique key of the date is the second barrier: a repeated date becomes the exception of the domain. */
    public function test_a_repeated_date_becomes_the_already_exists_exception(): void
    {
        $this->closures->save($this->closure('2026-12-25'));

        $this->expectException(ClosureAlreadyExistsException::class);
        $this->closures->save($this->closure('2026-12-25', 'Otra vez'));
    }

    /** A deleted closure is gone, and the repository of one barbershop never reaches the closures of another. */
    public function test_closures_are_deleted_and_isolated(): void
    {
        $own = $this->closure('2026-12-25');
        $this->closures->save($own);
        $other = $this->closure('2026-12-25');
        $this->scopeQueriesTo(self::FADE_LAB);
        new EloquentClosureRepository($this->contextOf(self::FADE_LAB))->save($other);
        $this->scopeQueriesTo(self::PURA_NAVAJA);

        $this->assertNull($this->closures->find($other->id));
        $this->assertEquals([$own], $this->closures->from(new ClosureDate('2026-01-01')));

        $this->closures->delete($other);
        $this->scopeQueriesTo(self::FADE_LAB);
        $this->assertSame(1, DB::table('closures')->count());

        $this->scopeQueriesTo(self::PURA_NAVAJA);
        $this->closures->delete($own);
        $this->assertNull($this->closures->find($own->id));
    }

    /** The ports are bound to their adapters. */
    public function test_ports_are_bound_to_their_adapters(): void
    {
        $this->assertInstanceOf(EloquentTimeSlotRepository::class, $this->app->make(TimeSlotRepository::class));
        $this->assertInstanceOf(ClosuresClosedDates::class, $this->app->make(ClosedDates::class));
    }

    /**
     * What availability reads, through the real adapters: a Sunday without slots and a holiday marked as closed have no
     * ranges, and a normal day has its own (RF-04).
     */
    public function test_open_ranges_come_from_the_schedule_and_the_closures(): void
    {
        $this->app->instance(BarbershopContext::class, $this->contextOf(self::PURA_NAVAJA));
        $this->slots->save($this->slot(5, '13:00', '18:00'));
        $this->slots->save($this->slot(5, '09:00', '12:00'));
        $this->closures->save($this->closure('2026-12-25', 'Navidad'));
        $query = $this->app->make(ListOpenRangesOnDate::class);

        $friday = $query->execute($this->at('2026-12-18 08:00'));
        $this->assertSame(['09:00-12:00', '13:00-18:00'], array_map(fn (TimeRange $range): string => "{$range->start->value}-{$range->end->value}", $friday));
        $this->assertSame([], $query->execute($this->at('2026-12-20 10:00')));
        $this->assertSame([], $query->execute($this->at('2026-12-25 10:00')));
    }

    /** Build a slot with a new identity. */
    private function slot(int $day, string $opens, string $closes): TimeSlot
    {
        return TimeSlot::create(
            $this->slots->nextId(),
            new DayOfWeek($day),
            TimeRange::between(new TimeOfDay($opens), new TimeOfDay($closes)),
        );
    }

    /** Build a closure with a new identity, registered "today" the first of January 2026. */
    private function closure(string $date, ?string $reason = null): Closure
    {
        return Closure::create(
            $this->closures->nextId(),
            new ClosureDate($date),
            $reason === null ? null : new ClosureReason($reason),
            new ClosureDate('2026-01-01'),
        );
    }

    /** Save a slot as the barbershop would, leaving the queries scoped to the first one again. */
    private function saveSlotFor(string $barbershopId, TimeSlot $slot): void
    {
        $this->scopeQueriesTo($barbershopId);
        new EloquentTimeSlotRepository($this->contextOf($barbershopId))->save($slot);
        $this->scopeQueriesTo(self::PURA_NAVAJA);
    }

    private function contextOf(string $barbershopId): BarbershopContext
    {
        return new FixedBarbershopContext(new BarbershopId($barbershopId));
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
