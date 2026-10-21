<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Infrastructure\Availability\AvailabilityFreeSlots;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\TestCase;

/**
 * A customer books a space of Daniela (RF-11): the reservation is confirmed with the copies of the service, the first one
 * creates the customer role, a taken space answers `espacio-no-disponible` with alternatives and leaves no role behind.
 */
final class CreateReservationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private string $ana;

    private string $luis;

    /** Seed the barbershops and two accounts without any role: Ana and Luis. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->ana = $this->insertUser('Ana Rojas');
        $this->luis = $this->insertUser('Luis Mora');
    }

    /** A person without a role books: the answer is the confirmed reservation with the copies of the service, and they become a customer (UP-09, RE-07, SV-03). */
    public function test_a_person_without_a_role_books_and_becomes_a_customer(): void
    {
        $this->actAs($this->ana);

        $response = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $response->assertJsonPath('data.servicio', $this->degradado)
            ->assertJsonPath('data.barbero', $this->daniela)
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.inicio', '2030-03-05T10:00:00-06:00')
            ->assertJsonPath('data.fin', '2030-03-05T10:45:00-06:00')
            ->assertJsonPath('data.precio', 7000)
            ->assertJsonPath('data.duracion_minutos', 45)
            ->assertJsonPath('data.plazo_cancelacion_horas', Reservation::DEFAULT_CANCELLATION_DEADLINE_HOURS);

        $membership = DB::table('memberships')->where('user_id', $this->ana)->where('barbershop_id', $this->puraNavaja)->first();
        $this->assertNotNull($membership);
        $this->assertSame('customer', $membership->role);
        $this->assertTrue($membership->is_active);

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('reservations')->where('id', $response->json('data.id'))->first();
        $this->assertNotNull($row);
        $this->assertSame($membership->id, $row->customer_membership_id);
        $this->assertSame('confirmed', $row->status);
        $this->assertSame([7000, 45, Reservation::DEFAULT_CANCELLATION_DEADLINE_HOURS], [$row->price, $row->duration_minutes, $row->cancellation_deadline_hours]);
    }

    /** The history has one entry for the creation and one for the confirmation, both by the person who booked (RE-07, RE-09). */
    public function test_the_history_has_the_creation_and_the_confirmation(): void
    {
        $this->actAs($this->ana);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        $this->scopeQueriesTo($this->puraNavaja);
        $history = DB::table('reservation_status_history')->where('reservation_id', $id)->orderBy('changed_at')->orderBy('to_status', 'desc')->get();

        $this->assertCount(2, $history);
        $this->assertEqualsCanonicalizing([[null, 'temporary'], ['temporary', 'confirmed']], $history->map(fn (object $entry): array => [$entry->from_status, $entry->to_status])->all());
        $this->assertSame([$this->ana, $this->ana], $history->pluck('changed_by_user_id')->all());
    }

    /** The reservation keeps the price it was booked with when the catalog changes later (SV-03). */
    public function test_the_reservation_keeps_the_price_after_the_catalog_changes(): void
    {
        $this->actAs($this->ana);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('services')->where('id', $this->degradado)->update(['price' => 9000, 'duration_minutes' => 60]);

        $row = DB::table('reservations')->where('id', $id)->first();
        $this->assertSame([7000, 45], [$row?->price, $row?->duration_minutes]);
    }

    /** The second reservation of the same person reuses their customer role instead of creating another. */
    public function test_a_second_reservation_reuses_the_membership(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated();

        $this->assertSame(1, DB::table('memberships')->where('user_id', $this->ana)->count());
        $this->assertSame(2, $this->countIn('reservations', $this->puraNavaja));
    }

    /** The loser of two requests for the same space gets `espacio-no-disponible` with the closest alternatives of the day, and only one reservation exists (RE-01, RE-02). */
    public function test_the_second_request_for_a_space_gets_the_alternatives(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
        $this->actAs($this->luis);

        $response = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible')
            ->assertJsonPath('status', 409);

        $this->assertSame(
            [
                ['barbero' => $this->daniela, 'inicio' => '2030-03-05T09:00:00-06:00', 'fin' => '2030-03-05T09:45:00-06:00'],
                ['barbero' => $this->daniela, 'inicio' => '2030-03-05T11:00:00-06:00', 'fin' => '2030-03-05T11:45:00-06:00'],
                ['barbero' => $this->daniela, 'inicio' => '2030-03-05T13:00:00-06:00', 'fin' => '2030-03-05T13:45:00-06:00'],
            ],
            $response->json('alternativas'),
        );
        $this->assertSame(1, $this->countIn('reservations', $this->puraNavaja));
    }

    /** A person whose first reservation fails is not left as a customer: the table of memberships has no row for them (RF-11, UP-09). */
    public function test_a_failed_first_reservation_leaves_no_membership(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
        $this->assertFalse(DB::table('memberships')->where('user_id', $this->luis)->exists());

        $this->actAs($this->luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->luis)->count());
        $this->assertSame(0, DB::table('memberships')->where('barbershop_id', $this->puraNavaja)->where('role', 'customer')->where('user_id', $this->luis)->count());
    }

    /**
     * The second barrier: when the calculation says the space is free but the database already has the collision (here the
     * check is replaced by one that always says free), the exclusion rejects it, the error is the same with its alternatives
     * and the savepoint of the reservation takes the new role with it.
     */
    public function test_the_exclusion_of_the_database_is_the_second_barrier_and_rolls_back_the_membership(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $real = $this->app->make(AvailabilityFreeSlots::class);
        $this->app->bind(FreeSlots::class, fn (): FreeSlots => new class($real) implements FreeSlots
        {
            public function __construct(private readonly FreeSlots $real) {}

            public function isFree(string $serviceId, string $collaboratorId, DateTimeImmutable $startsAt, DateTimeImmutable $now): bool
            {
                return true;
            }

            public function alternatives(string $serviceId, string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now): array
            {
                return $this->real->alternatives($serviceId, $collaboratorId, $requested, $now);
            }
        });
        $this->actAs($this->luis);

        $response = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T10:15:00-06:00'))
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->assertCount(3, $response->json('alternativas'));
        $this->assertSame(1, $this->countIn('reservations', $this->puraNavaja));
        $this->assertSame(2, $this->countIn('reservation_status_history', $this->puraNavaja));
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->luis)->count());
    }

    /** A space beyond the 30 days of the window is not available and has no alternatives that day (DI-03). */
    public function test_a_space_outside_the_booking_window_has_no_alternatives(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-04-02T10:00:00-06:00'))
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible')
            ->assertJsonPath('alternativas', []);

        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count());
    }

    /** A start that is not on the grid of the quarter hours is not a space (DI-02). */
    public function test_a_start_off_the_grid_is_not_available(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T10:07:00-06:00'))
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');
    }

    /** The instant is the same whatever the offset it is written with. */
    public function test_the_start_may_be_written_with_another_offset(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T16:00:00+00:00'))
            ->assertCreated()
            ->assertJsonPath('data.inicio', '2030-03-05T10:00:00-06:00');
    }

    /** A customer cannot hold two confirmed reservations that overlap, but back-to-back ones are fine (RE-06). */
    public function test_a_customer_cannot_book_two_overlapping_spaces(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(collaboratorId: $this->kevin, startsAt: '2030-03-05T10:30:00-06:00'))
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/conflicto')
            ->assertJsonPath('detail', 'Ya tiene una reserva confirmada que se solapa con ese horario en esta barbería.');
        $this->assertSame(1, $this->countIn('reservations', $this->puraNavaja));

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(collaboratorId: $this->kevin, startsAt: '2030-03-05T10:45:00-06:00'))->assertCreated();
        $this->assertSame(2, $this->countIn('reservations', $this->puraNavaja));
    }

    /** The reservation is in the name of the account of the session, whatever else the body says (CL-05). */
    public function test_the_reservation_is_in_the_name_of_the_account_of_the_session(): void
    {
        $this->actAs($this->ana);

        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), [
            ...$this->booking(),
            'cliente' => $this->luis,
            'usuario_id' => $this->luis,
            'barberia' => $this->fadeLab,
            'barbershop_id' => $this->fadeLab,
        ])->assertCreated()->json('data.id');

        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->luis)->count());
        $this->assertSame(0, $this->countIn('reservations', $this->fadeLab));
        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('reservations')->where('id', $id)->first();
        $this->assertSame($this->ana, DB::table('memberships')->where('id', $row?->customer_membership_id)->value('user_id'));
    }

    /** A person with a role in another barbershop is a customer here (UP-07, RD-18). */
    public function test_an_owner_of_another_barbershop_books_as_a_customer(): void
    {
        $this->grantRole($this->ana, $this->fadeLab, 'owner');
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $this->assertSame(
            ['customer', 'owner'],
            DB::table('memberships')->where('user_id', $this->ana)->orderBy('role')->pluck('role')->all(),
        );
    }

    /** An invalid body answers 422 with the field, and nothing is created. */
    public function test_invalid_data_is_rejected_with_the_field(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), ['servicio' => 'x', 'inicio' => '5 de marzo'])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonStructure(['errores' => ['servicio', 'barbero', 'inicio']]);

        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->ana)->count());
    }

    /** Without a session the answer is 401. */
    public function test_a_request_without_a_session_is_unauthenticated(): void
    {
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
            ->assertUnauthorized()
            ->assertJsonPath('type', 'http://localhost/problemas/no-autenticado');
    }
}
