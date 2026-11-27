<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\AssistedClients;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * The owner registers a reservation for a person without an account (RF-12, CL-06, RE-07, UP-03): the reservation is confirmed in the same
 * operation, takes the space like an online one, belongs to an assisted client of its barbershop and nothing else changes for it: the agenda,
 * the detail, the reschedule, the cancellation and the closing work as for any reservation. Today is Friday 1 March 2030, 8:00.
 */
final class RegisterAssistedReservationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private MutableClock $clock;

    private string $danielaUser;

    private string $ana;

    /** Seed the barbershops and a customer, and let the test move the clock. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->danielaUser = (string) DB::table('collaborators')->where('id', $this->daniela)->value('user_id');
        [$this->ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
    }

    /**
     * The body of an assisted reservation for a new person.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assisted(array $overrides = []): array
    {
        return [...$this->booking(), 'cliente_nombre' => 'Rodrigo Chaves', 'cliente_telefono' => '8812 3456', 'cliente_correo' => 'rodrigo@correo.cr', ...$overrides];
    }

    private function assistedUrl(?string $barbershopId = null): string
    {
        return $this->reservationsUrl($barbershopId ?? $this->puraNavaja).'/asistida';
    }

    /**
     * Register an assisted reservation as the owner and return its identifier.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function register(array $overrides = []): string
    {
        $this->actAs($this->owner);

        return (string) $this->postJson($this->assistedUrl(), $this->assisted($overrides))->assertCreated()->json('data.id');
    }

    /** The reservation is confirmed in the same operation, with no online payment, and keeps the copies of the service (RF-12, RE-07, SV-03). */
    public function test_the_owner_registers_a_confirmed_reservation_for_a_new_person(): void
    {
        $this->actAs($this->owner);

        $response = $this->postJson($this->assistedUrl(), $this->assisted())->assertCreated();

        $response->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.expira_en', null)
            ->assertJsonPath('data.servicio', $this->degradado)
            ->assertJsonPath('data.barbero', $this->daniela)
            ->assertJsonPath('data.inicio', '2030-03-05T10:00:00-06:00')
            ->assertJsonPath('data.fin', '2030-03-05T10:45:00-06:00')
            ->assertJsonPath('data.precio', 7000)
            ->assertJsonPath('data.duracion_minutos', 45);

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('reservations')->where('id', $response->json('data.id'))->first();
        $this->assertNotNull($row);
        $this->assertSame('confirmed', $row->status);
        $this->assertNull($row->customer_membership_id);
        $this->assertSame($response->json('data.cliente_asistido'), $row->assisted_client_id);

        $client = DB::table('assisted_clients')->where('id', $row->assisted_client_id)->first();
        $this->assertSame(['Rodrigo Chaves', '8812 3456', 'rodrigo@correo.cr'], [$client?->name, $client?->phone, $client?->email]);
    }

    /** Even when the barbershop charges online, the assisted reservation is confirmed at once and asks for no payment (RE-07). */
    public function test_a_barbershop_that_charges_confirms_it_anyway(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);

        $id = $this->register();

        $this->assertSame('confirmed', DB::table('reservations')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('reservations')->where('id', $id)->value('expires_at'));
        $this->assertSame(0, DB::table('payments')->count());
    }

    /** The owner who registered it is its author and stays on record, and the history has the creation and the confirmation (RE-09). */
    public function test_the_history_and_the_creator_name_the_owner(): void
    {
        $id = $this->register();

        $this->scopeQueriesTo($this->puraNavaja);
        $history = DB::table('reservation_status_history')->where('reservation_id', $id)->get();
        $this->assertEqualsCanonicalizing([[null, 'temporary'], ['temporary', 'confirmed']], $history->map(fn (object $entry): array => [$entry->from_status, $entry->to_status])->all());
        $this->assertSame([$this->owner, $this->owner], $history->pluck('changed_by_user_id')->all());

        $ownerRole = DB::table('memberships')->where('user_id', $this->owner)->where('barbershop_id', $this->puraNavaja)->value('id');
        $this->assertSame($ownerRole, DB::table('reservations')->where('id', $id)->value('created_by_membership_id'));
    }

    /** The person gets no account and no customer role (CL-06): the owner remains the only one with a role in the barbershop besides the staff and Ana. */
    public function test_the_assisted_client_is_neither_an_account_nor_a_membership(): void
    {
        $accounts = DB::table('users')->count();
        $roles = DB::table('memberships')->count();

        $this->register();

        $this->assertSame($accounts, DB::table('users')->count());
        $this->assertSame($roles, DB::table('memberships')->count());
    }

    /** The space is taken: nobody else, online or assisted, gets it (RE-01). */
    public function test_it_takes_the_space_like_an_online_reservation(): void
    {
        $this->register();

        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->actAs($this->owner);
        $this->postJson($this->assistedUrl(), $this->assisted(['cliente_nombre' => 'Otra Persona']))->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');
        $this->assertSame(1, $this->countIn('reservations', $this->puraNavaja));
    }

    /** An online reservation took the space first: the assisted one answers `espacio-no-disponible` with the closest alternatives and leaves no client behind (RE-02). */
    public function test_a_taken_space_answers_with_alternatives_and_leaves_no_client_behind(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $this->actAs($this->owner);
        $response = $this->postJson($this->assistedUrl(), $this->assisted())
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->assertSame(
            [
                ['barbero' => $this->daniela, 'inicio' => '2030-03-05T09:00:00-06:00', 'fin' => '2030-03-05T09:45:00-06:00'],
                ['barbero' => $this->daniela, 'inicio' => '2030-03-05T11:00:00-06:00', 'fin' => '2030-03-05T11:45:00-06:00'],
                ['barbero' => $this->daniela, 'inicio' => '2030-03-05T13:00:00-06:00', 'fin' => '2030-03-05T13:45:00-06:00'],
            ],
            $response->json('alternativas'),
        );
        $this->assertSame(0, $this->countIn('assisted_clients', $this->puraNavaja));
        $this->assertSame(1, $this->countIn('reservations', $this->puraNavaja));
    }

    /** A space outside the schedule is not free either: the owner chooses among the spaces of the availability (RF-10, DI-01). */
    public function test_a_space_outside_the_shift_is_not_registered(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->assistedUrl(), $this->assisted(['inicio' => '2030-03-05T20:00:00-06:00']))->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');
        $this->assertSame(0, $this->countIn('assisted_clients', $this->puraNavaja));
    }

    /** The owner picks a person registered before: no new person is created, and the same one has two reservations (RF-12). */
    public function test_an_existing_assisted_client_is_reused(): void
    {
        $this->actAs($this->owner);
        $clientId = $this->postJson($this->assistedUrl(), $this->assisted())->assertCreated()->json('data.cliente_asistido');

        $this->postJson($this->assistedUrl(), ['servicio' => $this->degradado, 'barbero' => $this->daniela, 'inicio' => '2030-03-05T14:00:00-06:00', 'cliente_asistido' => $clientId])
            ->assertCreated()
            ->assertJsonPath('data.cliente_asistido', $clientId);

        $this->assertSame(1, $this->countIn('assisted_clients', $this->puraNavaja));
        $this->assertSame([$clientId, $clientId], DB::table('reservations')->pluck('assisted_client_id')->all());
    }

    /** A client of another barbershop reads like one that does not exist (CL-06, UP-06). */
    public function test_an_assisted_client_of_another_barbershop_does_not_exist(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $foreign = (string) Str::uuid7();
        DB::table('assisted_clients')->insert(['id' => $foreign, 'barbershop_id' => $this->fadeLab, 'name' => 'Bea Solano', 'phone' => '88880000']);
        $this->scopeQueriesTo($this->puraNavaja);

        $this->actAs($this->owner);
        $response = $this->postJson($this->assistedUrl(), ['servicio' => $this->degradado, 'barbero' => $this->daniela, 'inicio' => self::TUESDAY_TEN, 'cliente_asistido' => $foreign])->assertNotFound();

        $this->assertSame('http://localhost/problemas/no-encontrado', $response->json('type'));
        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
    }

    /** The name and the phone are required for a new person and the email is optional and checked; each error names its field (CL-06). */
    public function test_the_data_of_a_new_person_is_validated_by_field(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->assistedUrl(), $this->booking())
            ->assertUnprocessable()
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonStructure(['errores' => ['cliente_nombre', 'cliente_telefono']]);

        $this->postJson($this->assistedUrl(), $this->assisted(['cliente_telefono' => '', 'cliente_correo' => 'no-es-un-correo']))
            ->assertUnprocessable()
            ->assertJsonStructure(['errores' => ['cliente_telefono', 'cliente_correo']])
            ->assertJsonMissingPath('errores.cliente_nombre');

        $this->postJson($this->assistedUrl(), ['servicio' => 'x', 'barbero' => 'y', 'inicio' => '5 de marzo', 'cliente_nombre' => str_repeat('a', 121), 'cliente_telefono' => str_repeat('1', 31)])
            ->assertUnprocessable()
            ->assertJsonStructure(['errores' => ['servicio', 'barbero', 'inicio', 'cliente_nombre', 'cliente_telefono']]);

        $this->assertSame(0, $this->countIn('assisted_clients', $this->puraNavaja));
    }

    /** The collaborator who is a barber is chosen by the owner; the email can be left out and the person is still registered (CL-06). */
    public function test_the_email_is_optional(): void
    {
        $this->actAs($this->owner);

        $id = $this->postJson($this->assistedUrl(), $this->assisted(['cliente_correo' => null]))->assertCreated()->json('data.cliente_asistido');

        $this->assertNull(DB::table('assisted_clients')->where('id', $id)->value('email'));
    }

    /** A service or a barber that does not exist in the barbershop answers 404 and registers nobody (RF-10). */
    public function test_an_unknown_service_or_barber_is_not_found(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->assistedUrl(), $this->assisted(['servicio' => $this->corte]))->assertNotFound();
        $this->postJson($this->assistedUrl(), $this->assisted(['barbero' => $this->mauricio]))->assertNotFound();

        $this->assertSame(0, $this->countIn('assisted_clients', $this->puraNavaja));
        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
    }

    /** Only the owner registers: a collaborator and a customer get 403, a person without a role in the barbershop and the owner of another one get 404 (UP-03, UP-06). */
    public function test_only_the_owner_may_register(): void
    {
        $this->actAs($this->danielaUser);
        $this->postJson($this->assistedUrl(), $this->assisted())->assertForbidden();

        $this->actAs($this->ana);
        $this->postJson($this->assistedUrl(), $this->assisted())->assertForbidden();

        $foreignOwner = $this->insertUser('Dueño de Fade Lab');
        $this->grantRole($foreignOwner, $this->fadeLab, 'owner');
        $this->actAs($foreignOwner);
        $this->postJson($this->assistedUrl(), $this->assisted())->assertNotFound();

        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
        $this->assertSame(0, $this->countIn('assisted_clients', $this->puraNavaja));
    }

    /** The owner sees name and phone of the assisted client in the agenda; the collaborator sees the name and never the phone (CO-04, RF-13). */
    public function test_the_agenda_shows_the_assisted_client_to_the_owner_and_the_collaborator(): void
    {
        $assisted = $this->register();
        $online = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, (string) DB::table('memberships')->where('user_id', $this->ana)->value('id'), '2030-03-05T14:00:00-06:00');

        $this->actAs($this->owner);
        $list = $this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk();
        $byId = array_column($list->json('data'), null, 'id');
        $this->assertSame(['Rodrigo Chaves', true, '8812 3456'], [$byId[$assisted]['cliente_nombre'], $byId[$assisted]['asistida'], $byId[$assisted]['cliente_telefono']]);
        $this->assertSame(['Ana Rojas', false, null], [$byId[$online]['cliente_nombre'], $byId[$online]['asistida'], $byId[$online]['cliente_telefono']]);

        $this->actAs($this->danielaUser);
        $byId = array_column($this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk()->json('data'), null, 'id');
        $this->assertSame(['Rodrigo Chaves', true, null], [$byId[$assisted]['cliente_nombre'], $byId[$assisted]['asistida'], $byId[$assisted]['cliente_telefono']]);
        $this->assertStringNotContainsString('rodrigo@correo.cr', (string) json_encode($byId[$assisted]));
    }

    /** The detail shows the same to each role, with the history only for the owner (RF-14). */
    public function test_the_detail_shows_the_assisted_client(): void
    {
        $id = $this->register();
        $url = $this->reservationsUrl($this->puraNavaja)."/{$id}";

        $this->actAs($this->owner);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.cliente_nombre', 'Rodrigo Chaves')
            ->assertJsonPath('data.cliente_telefono', '8812 3456')
            ->assertJsonPath('data.asistida', true)
            ->assertJsonPath('data.saldo_pendiente', 7000)
            ->assertJsonCount(2, 'data.historial');

        $this->actAs($this->danielaUser);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.cliente_nombre', 'Rodrigo Chaves')
            ->assertJsonPath('data.cliente_telefono', null)
            ->assertJsonMissingPath('data.historial');

        $this->actAs($this->ana);
        $this->getJson($url)->assertNotFound();
    }

    /** The assisted client is nobody's "my reservations": no customer reads it (CL-04). */
    public function test_no_customer_lists_it_as_theirs(): void
    {
        $this->register();

        $this->actAs($this->ana);
        $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk()->assertJsonCount(0, 'data');
    }

    /** The collaborator of another agenda does not see it (CO-04, UP-06). */
    public function test_a_collaborator_without_that_agenda_does_not_see_it(): void
    {
        $id = $this->register();
        $kevin = (string) DB::table('collaborators')->where('id', $this->kevin)->value('user_id');

        $this->actAs($kevin);
        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$id}")->assertNotFound();
    }

    /** The owner reschedules it as any reservation: the old space is free and the client stays (RF-15). */
    public function test_the_owner_reschedules_an_assisted_reservation(): void
    {
        $id = $this->register();

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T14:00:00-06:00'])
            ->assertOk()
            ->assertJsonPath('data.inicio', '2030-03-05T14:00:00-06:00')
            ->assertJsonPath('data.estado', 'confirmada');

        $this->assertNotNull(DB::table('reservations')->where('id', $id)->value('assisted_client_id'));
        $this->assertSame(1, DB::table('reservations')->where('id', $id)->value('reschedule_count'));

        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
    }

    /** Rescheduling onto a taken space answers `espacio-no-disponible` and the assisted reservation stays as it was (RE-08). */
    public function test_rescheduling_onto_a_taken_space_leaves_it_as_it_was(): void
    {
        $id = $this->register();
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated();

        $this->actAs($this->owner);
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T14:00:00-06:00'])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->assertSame('2030-03-05 10:00:00-06', DB::table('reservations')->where('id', $id)->value('starts_at'));
    }

    /** The owner cancels it: the space is free in the same operation and the cancellation is the one of the barbershop (RF-16, CA-07). */
    public function test_the_owner_cancels_an_assisted_reservation_and_frees_the_space(): void
    {
        $id = $this->register();

        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada_por_barberia')
            ->assertJsonPath('data.clasificacion', 'por_la_barberia');

        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
    }

    /** A customer cannot cancel or reschedule the reservation of an assisted client: it is not theirs (UP-06). */
    public function test_a_customer_cannot_cancel_or_move_it(): void
    {
        $id = $this->register();
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")->assertNotFound();
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T14:00:00-06:00'])->assertNotFound();
        $this->assertSame('confirmed', DB::table('reservations')->where('id', $id)->value('status'));
    }

    /** The owner and the collaborator mark it attended or absent, and the attended one adds to the income report (RF-18, RF-25, PA-11). */
    public function test_it_is_closed_and_counts_in_the_income_report(): void
    {
        $attended = $this->register();
        $absent = $this->register(['inicio' => '2030-03-05T14:00:00-06:00', 'cliente_nombre' => 'Rosa Campos', 'cliente_telefono' => '8744 0021', 'cliente_correo' => null]);
        $this->clock->moveTo(new DateTimeImmutable('2030-03-05 15:00:00', new DateTimeZone('America/Costa_Rica')));

        $this->actAs($this->danielaUser);
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$attended}/cierre", ['estado' => 'atendida'])->assertOk()->assertJsonPath('data.estado', 'completada');
        $this->actAs($this->owner);
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$absent}/cierre", ['estado' => 'ausente'])->assertOk()->assertJsonPath('data.estado', 'ausente');

        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/administracion/ingresos?desde=2030-03-01&hasta=2030-03-31")
            ->assertOk()
            ->assertJsonPath('data.servicios_atendidos', ['total' => 7000, 'cantidad' => 1]);
    }

    /** The search finds the people of the barbershop by a part of the name or the phone and never those of another (RF-12, CL-06). */
    public function test_the_search_finds_only_the_clients_of_the_barbershop(): void
    {
        $this->register();
        $this->register(['inicio' => '2030-03-05T14:00:00-06:00', 'cliente_nombre' => 'Rosa Campos', 'cliente_telefono' => '8744 0021', 'cliente_correo' => null]);
        $this->scopeQueriesTo($this->fadeLab);
        DB::table('assisted_clients')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->fadeLab, 'name' => 'Rodrigo de Fade Lab', 'phone' => '8812 9999']);
        $this->scopeQueriesTo($this->puraNavaja);

        $url = "/api/v1/barberias/{$this->puraNavaja}/clientes-asistidos";
        $this->actAs($this->owner);

        $this->assertSame(['Rodrigo Chaves'], array_column($this->getJson("{$url}?busqueda=rodri")->assertOk()->json('data'), 'nombre'));
        $this->assertSame(['Rosa Campos'], array_column($this->getJson("{$url}?busqueda=8744")->assertOk()->json('data'), 'nombre'));
        $this->assertSame(['Rodrigo Chaves', 'Rosa Campos'], array_column($this->getJson($url)->assertOk()->json('data'), 'nombre'));
        $this->assertSame([], $this->getJson("{$url}?busqueda=Fade")->assertOk()->json('data'));
        $this->getJson("{$url}?busqueda=rodri")->assertJsonPath('data.0.telefono', '8812 3456')->assertJsonPath('data.0.correo', 'rodrigo@correo.cr');
    }

    /** The wildcards of a search are taken literally and a search brings at most ten people. */
    public function test_the_search_takes_wildcards_literally_and_stops_at_ten(): void
    {
        foreach (range(1, 12) as $number) {
            DB::table('assisted_clients')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'name' => sprintf('Cliente %02d', $number), 'phone' => "8000 00{$number}"]);
        }
        $url = "/api/v1/barberias/{$this->puraNavaja}/clientes-asistidos";
        $this->actAs($this->owner);

        $this->assertCount(10, $this->getJson($url)->assertOk()->json('data'));
        $this->assertSame([], $this->getJson("{$url}?busqueda=%25")->assertOk()->json('data'));
        $this->assertSame([], $this->getJson("{$url}?busqueda=_")->assertOk()->json('data'));
    }

    /** A collaborator and a customer cannot search, and the owner of another barbershop gets 404 (UP-03, UP-06). */
    public function test_only_the_owner_searches(): void
    {
        $url = "/api/v1/barberias/{$this->puraNavaja}/clientes-asistidos";

        $this->actAs($this->danielaUser);
        $this->getJson($url)->assertForbidden();
        $this->actAs($this->ana);
        $this->getJson($url)->assertForbidden();
        $foreignOwner = $this->insertUser('Dueño de Fade Lab');
        $this->grantRole($foreignOwner, $this->fadeLab, 'owner');
        $this->actAs($foreignOwner);
        $this->getJson($url)->assertNotFound();
    }
}
