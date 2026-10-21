<?php

declare(strict_types=1);

namespace Tests\Feature\Availability\Slots;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Availability\Slots\Application\UseCases\ListAlternativeSlots;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\Exceptions\ServiceNotBookableException;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/**
 * The availability over the real adapters of the other modules. Pura Navaja opens from Monday to Saturday from 9:00 to
 * 19:00 and closes on 12 March 2030. Daniela works on Tuesdays from 9:00 to 19:00 with a break from 12:00 to 13:00,
 * and has a confirmed reservation at 10:00 and a temporary one at 14:00; Kevin works that day from 9:00 to 12:00.
 */
final class ListSlotsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    /** A Tuesday, the Sunday before it and a Tuesday the barbershop is closed. */
    private const string TUESDAY = '2030-03-05';

    private const string SUNDAY = '2030-03-03';

    private const string CLOSED_TUESDAY = '2030-03-12';

    private string $puraNavaja;

    private string $fadeLab;

    private string $degradado;

    private string $daniela;

    private string $kevin;

    /** Build Pura Navaja with its hours, its service, two collaborators who offer it and the reservations of Daniela. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->actAs($this->puraNavaja);

        foreach (range(1, 6) as $day) {
            DB::table('opening_hours')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '19:00']);
        }
        DB::table('closures')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'closed_on' => self::CLOSED_TUESDAY]);

        $this->degradado = $this->insertService($this->puraNavaja, 'Degradado', 45);
        $this->daniela = $this->insertCollaborator($this->puraNavaja, [$this->degradado], [[2, '09:00', '19:00']], [[2, '12:00', '13:00']]);
        $this->kevin = $this->insertCollaborator($this->puraNavaja, [$this->degradado], [[2, '09:00', '12:00']]);

        $this->insertReservation($this->daniela, 'confirmed', '2030-03-05 10:00');
        $this->insertReservation($this->daniela, 'temporary', '2030-03-05 14:00');
    }

    /** Daniela's Tuesday: the break, the confirmed and the temporary reservation (with their cleaning) take their spaces (DI-01, DI-05). */
    public function test_the_spaces_of_daniela_leave_out_the_break_and_the_reservations(): void
    {
        $slots = $this->useCase()->execute($this->degradado, self::TUESDAY, $this->daniela, $this->now());

        $this->assertSame(
            ['09:00', '11:00', '13:00', '15:00', '15:15', '15:30', '15:45', '16:00', '16:15', '16:30', '16:45', '17:00', '17:15', '17:30', '17:45', '18:00'],
            $this->starts($slots),
        );
        $this->assertSame('09:45', $slots[0]->end->format('H:i'));
    }

    /** Without a collaborator the spaces of everybody who offers the service come together; the ones who do not offer it or are inactive are left out (CO-01). */
    public function test_lists_the_collaborators_who_offer_the_service(): void
    {
        $other = $this->insertService($this->puraNavaja, 'Barba', 30);
        $this->insertCollaborator($this->puraNavaja, [$other], [[2, '09:00', '19:00']]);
        $this->insertCollaborator($this->puraNavaja, [$this->degradado], [[2, '09:00', '19:00']], active: false);

        $slots = $this->useCase()->execute($this->degradado, self::TUESDAY, null, $this->now());

        $this->assertEqualsCanonicalizing([$this->daniela, $this->kevin], array_values(array_unique(array_map(fn (Slot $slot): string => $slot->collaboratorId->value, $slots))));
        $this->assertSame('09:00', $slots[0]->start->format('H:i'));
        $this->assertSame([], $this->useCase()->execute($this->degradado, self::TUESDAY, $this->insertCollaborator($this->puraNavaja, [$other], [[2, '09:00', '19:00']]), $this->now()));
    }

    /** A Sunday, and a Tuesday with a closure, have no spaces (HO-01); neither has a day without a shift. */
    public function test_closed_days_have_no_spaces(): void
    {
        $this->assertSame([], $this->useCase()->execute($this->degradado, self::SUNDAY, null, $this->now()));
        $this->assertSame([], $this->useCase()->execute($this->degradado, self::CLOSED_TUESDAY, null, $this->now()));
        $this->assertSame([], $this->useCase()->execute($this->degradado, '2030-03-06', null, $this->now()));
    }

    /** The notice and the window of DI-03 hold with the real data. */
    public function test_the_notice_and_the_window_hold(): void
    {
        $this->assertSame(['11:00', '13:00'], array_slice($this->starts($this->useCase()->execute($this->degradado, self::TUESDAY, $this->daniela, $this->at('2030-03-05 09:30'))), 0, 2));
        $this->assertSame([], $this->useCase()->execute($this->degradado, self::TUESDAY, $this->daniela, $this->at('2030-01-01 08:00')));
    }

    /** An inactive service, or one of another barbershop, cannot be booked. */
    public function test_a_service_of_another_barbershop_is_not_bookable(): void
    {
        $this->actAs($this->fadeLab);

        $this->expectException(ServiceNotBookableException::class);

        $this->useCase()->execute($this->degradado, self::TUESDAY, null, $this->now());
    }

    /** The reservations of another barbershop never take a space, even at the same hour. */
    public function test_reservations_of_another_barbershop_do_not_block(): void
    {
        $this->actAs($this->fadeLab);
        DB::table('opening_hours')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->fadeLab, 'day_of_week' => 2, 'opens_at' => '09:00', 'closes_at' => '19:00']);
        $service = $this->insertService($this->fadeLab, 'Degradado', 45);
        $mauricio = $this->insertCollaborator($this->fadeLab, [$service], [[2, '09:00', '19:00']]);

        $slots = $this->useCase()->execute($service, self::TUESDAY, null, $this->now());

        $this->assertSame([$mauricio], array_values(array_unique(array_map(fn (Slot $slot): string => $slot->collaboratorId->value, $slots))));
        $this->assertSame('09:00', $slots[0]->start->format('H:i'));
        $this->assertContains('10:00', $this->starts($slots));
    }

    /** Asking for the day takes the same queries with 2 collaborators as with 6: the reservations are read once, not once per collaborator. */
    public function test_the_number_of_queries_does_not_grow_with_the_collaborators(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->useCase()->execute($this->degradado, self::TUESDAY, null, $this->now());
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $withTwo = $count();
        foreach (range(1, 4) as $unused) {
            $this->insertCollaborator($this->puraNavaja, [$this->degradado], [[2, '09:00', '19:00']], [[2, '12:00', '13:00']]);
        }
        $withSix = $count();

        $this->assertSame($withTwo, $withSix);
    }

    /** When 10:00 of Daniela is taken, the alternatives are the three closest spaces of her day, and a tie keeps both. */
    public function test_the_alternatives_are_the_closest_free_spaces_of_the_day(): void
    {
        $alternatives = $this->app->make(ListAlternativeSlots::class)
            ->execute($this->degradado, $this->daniela, $this->at('2030-03-05 10:00'), $this->now());

        $this->assertSame(['09:00', '11:00', '13:00'], $this->starts($alternatives));
    }

    /** Make the queries and the use cases of the test work for a barbershop. */
    private function actAs(string $barbershopId): void
    {
        $this->scopeQueriesTo($barbershopId);
        $this->app->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
    }

    private function useCase(): ListAvailableSlots
    {
        return $this->app->make(ListAvailableSlots::class);
    }

    private function now(): DateTimeImmutable
    {
        return $this->at('2030-03-01 08:00');
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /**
     * @param  list<Slot>  $slots
     * @return list<string>
     */
    private function starts(array $slots): array
    {
        return array_map(fn (Slot $slot): string => $slot->start->format('H:i'), $slots);
    }

    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    private function insertService(string $barbershopId, string $name, int $minutes): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => $minutes, 'price' => 8500]);

        return $id;
    }

    /**
     * Insert a collaborator with the services, shifts and breaks given as [day, from, to].
     *
     * @param  list<string>  $services
     * @param  list<array{int, string, string}>  $shifts
     * @param  list<array{int, string, string}>  $breaks
     */
    private function insertCollaborator(string $barbershopId, array $services, array $shifts, array $breaks = [], bool $active = true): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => 'Colaborador', 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $userId, 'is_active' => $active]);

        foreach ($services as $service) {
            DB::table('collaborator_services')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $id, 'service_id' => $service]);
        }
        foreach ([['collaborator_shifts', $shifts], ['collaborator_breaks', $breaks]] as [$table, $rows]) {
            foreach ($rows as [$day, $from, $to]) {
                DB::table($table)->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $barbershopId, 'collaborator_id' => $id, 'day_of_week' => $day, 'starts_at' => $from, 'ends_at' => $to]);
            }
        }

        return $id;
    }

    /** Insert a 45-minute reservation of a new customer, with its 5 minutes of cleaning. */
    private function insertReservation(string $collaboratorId, string $status, string $startsAt): void
    {
        $userId = (string) Str::uuid7();
        $customer = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => 'Ana Rojas', 'email' => "{$userId}@barberhub.test", 'password' => 'hash']);
        DB::table('memberships')->insert(['id' => $customer, 'user_id' => $userId, 'barbershop_id' => $this->puraNavaja, 'role' => 'customer']);
        $start = $this->at($startsAt);

        DB::table('reservations')->insert([
            'id' => (string) Str::uuid7(),
            'barbershop_id' => $this->puraNavaja,
            'collaborator_id' => $collaboratorId,
            'service_id' => $this->degradado,
            'customer_membership_id' => $customer,
            'status' => $status,
            'starts_at' => $start,
            'ends_at' => $start->modify('+45 minutes'),
            'blocked_until' => $start->modify('+50 minutes'),
            'price' => 8500,
            'duration_minutes' => 45,
            'cancellation_deadline_hours' => 4,
        ]);
    }
}
