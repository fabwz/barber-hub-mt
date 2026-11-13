<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\AccessMatrix;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\Support\Tenancy\RouteRule;
use Tests\TestCase;

/**
 * What the matrix cannot say with one request per person: identifiers of another barbershop in the URL and in the body, fields
 * a body must not be able to set, the roles of the staff against money and the business, a token used where it does not belong,
 * the barbershop named in a header or a query, and the pages and filters of the listings (RF-42, UP-02, UP-06, UP-07, UP-09, MT-02,
 * MT-03, CO-04, CL-04). Whatever a test finds is reported, never relaxed.
 */
final class AdversarialAccessTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    private const string LUIS_AT_ELEVEN = '2030-03-05T11:00:00-06:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAccessScenario();
    }

    /** A person never holds two roles in the same barbershop (UP-07): the database refuses it, and so does the API for an owner who tries to book. */
    public function test_a_person_never_holds_two_roles_in_the_same_barbershop(): void
    {
        try {
            // Inside its own savepoint, so that the refusal does not abort the transaction of the test.
            DB::transaction(fn () => $this->grantRole($this->ana, $this->puraNavaja, 'owner'));
            $this->fail('A second role in the same barbershop was accepted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('memberships')->where('user_id', $this->ana)->where('barbershop_id', $this->puraNavaja)->count());
        }
    }

    /** The owner or a collaborator who tries a customer operation in their own barbershop gets 403 "prohibido" and no customer role is created (UP-09). */
    public function test_the_staff_cannot_become_customers_of_their_own_barbershop(): void
    {
        $memberships = DB::table('memberships')->count();

        foreach (['owner', 'collaborator', 'assigned_collaborator'] as $actor) {
            $this->sendAs($actor, 'POST', $this->addressOf('api/v1/barberias/{barberia}/reservas', $this->puraNavaja, []), $this->booking(startsAt: self::LUIS_AT_ELEVEN))
                ->assertForbidden()
                ->assertJsonPath('type', fn (mixed $type): bool => is_string($type) && str_ends_with($type, 'prohibido'));
        }

        $this->assertSame($memberships, DB::table('memberships')->count());
    }

    /** A service or a collaborator of another barbershop in the body of a booking is as missing as a random one, and nothing is booked. */
    public function test_a_booking_with_the_identifiers_of_another_barbershop_is_refused_like_unknown_ones(): void
    {
        $url = $this->addressOf('api/v1/barberias/{barberia}/reservas', $this->puraNavaja, []);
        $before = $this->fingerprint();
        $unknownService = $this->sendAs('other_customer', 'POST', $url, $this->booking(serviceId: (string) Str::uuid7(), collaboratorId: $this->kevin, startsAt: self::LUIS_AT_ELEVEN));
        $unknownCollaborator = $this->sendAs('other_customer', 'POST', $url, $this->booking(collaboratorId: (string) Str::uuid7(), startsAt: self::LUIS_AT_ELEVEN));

        $foreignService = $this->sendAs('other_customer', 'POST', $url, $this->booking(serviceId: $this->corte, collaboratorId: $this->kevin, startsAt: self::LUIS_AT_ELEVEN));
        $foreignCollaborator = $this->sendAs('other_customer', 'POST', $url, $this->booking(collaboratorId: $this->mauricio, startsAt: self::LUIS_AT_ELEVEN));

        $this->assertGreaterThanOrEqual(400, $foreignService->getStatusCode());
        $this->assertLessThan(500, $foreignService->getStatusCode());
        $this->assertSame($unknownService->getStatusCode(), $foreignService->getStatusCode());
        $this->assertSame($this->problemOf($unknownService), $this->problemOf($foreignService));
        $this->assertSame($unknownCollaborator->getStatusCode(), $foreignCollaborator->getStatusCode());
        $this->assertSame($this->problemOf($unknownCollaborator), $this->problemOf($foreignCollaborator));
        $this->assertSame($before, $this->fingerprint());
    }

    /** The barbershop, the price, the state, the customer and the role a body names are ignored: the reservation is the one the URL, the service and the session say (MT-03, CL-05, PA-06). */
    public function test_a_booking_ignores_the_protected_fields_of_its_body(): void
    {
        $response = $this->sendAs('other_customer', 'POST', $this->addressOf('api/v1/barberias/{barberia}/reservas', $this->puraNavaja, []), [
            ...$this->booking(collaboratorId: $this->kevin, startsAt: self::LUIS_AT_ELEVEN),
            'barberia' => $this->fadeLab,
            'barbershop_id' => $this->fadeLab,
            'cliente' => $this->bea,
            'customer_membership_id' => $this->anaMembership,
            'estado' => 'completada',
            'status' => 'completed',
            'precio' => 1,
            'monto' => 1,
            'rol' => 'owner',
        ])->assertCreated();

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('reservations')->where('id', $response->json('data.id'))->first();
        $this->assertNotNull($row);
        $this->assertSame($this->puraNavaja, $row->barbershop_id);
        $this->assertSame($this->luisMembership, $row->customer_membership_id);
        // The barbershop asks for no payment, so the system confirms it; the state of the body ("completada") is not taken.
        $this->assertSame('confirmed', $row->status);
        $this->assertSame(7000, $row->price);
        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame(1, DB::table('reservations')->count());
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->luis)->where('role', '!=', 'customer')->count());
    }

    /** The registration of an account ignores the role, the barbershop and the state a body names: nobody gets a role by registering (UP-09). */
    public function test_a_registration_cannot_set_a_role_or_a_barbershop(): void
    {
        $memberships = DB::table('memberships')->count();
        $id = (string) Str::uuid7();

        $response = $this->sendAs('anonymous', 'POST', '/api/v1/cuentas', [
            'nombre' => 'Persona Nueva',
            'correo' => 'nueva@barberhub.test',
            'telefono' => '8888-0000',
            'contrasena' => 'Demo#Barber2026',
            'acepta_terminos' => true,
            'acepta_privacidad' => true,
            'id' => $id,
            'rol' => 'owner',
            'role' => 'owner',
            'barberia' => $this->puraNavaja,
            'es_activo' => false,
            'is_active' => false,
        ])->assertCreated();

        $this->assertNotSame($id, $response->json('data.id'));
        $this->assertSame($memberships, DB::table('memberships')->count());
        $this->assertTrue((bool) DB::table('users')->where('email', 'nueva@barberhub.test')->value('is_active'));
    }

    /** The owner cannot move a service to another barbershop, give it another identifier or touch one of another barbershop. */
    public function test_the_owner_cannot_move_or_reach_a_service_of_another_barbershop(): void
    {
        $this->sendAs('owner', 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/servicios/{servicio}', $this->puraNavaja, $this->resources), [
            'nombre' => 'Degradado clásico',
            'barberia' => $this->fadeLab,
            'barbershop_id' => $this->fadeLab,
            'id' => (string) Str::uuid7(),
        ])->assertOk();

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('services')->where('id', $this->degradado)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->puraNavaja, $row->barbershop_id);
        $this->assertSame('Degradado clásico', $row->name);

        $before = $this->fingerprint();
        $foreign = $this->sendAs('owner', 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/servicios/{servicio}', $this->puraNavaja, ['servicio' => $this->corte]), ['nombre' => 'Robado']);
        $missing = $this->sendAs('owner', 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/servicios/{servicio}', $this->puraNavaja, ['servicio' => (string) Str::uuid7()]), ['nombre' => 'Robado']);

        $foreign->assertNotFound();
        $this->assertSame($this->problemOf($missing), $this->problemOf($foreign));
        $this->assertSame($before, $this->fingerprint());
    }

    /** The owner cannot enable a service of another barbershop for a collaborator, nor edit a collaborator of another barbershop. */
    public function test_the_owner_cannot_link_the_services_or_staff_of_another_barbershop(): void
    {
        $before = $this->fingerprint();
        $services = 'api/v1/barberias/{barberia}/administracion/colaboradores/{colaborador}/servicios';
        $schedule = 'api/v1/barberias/{barberia}/administracion/colaboradores/{colaborador}/horario';

        $foreignService = $this->sendAs('owner', 'PUT', $this->addressOf($services, $this->puraNavaja, $this->resources), ['servicios' => [$this->corte]]);
        $unknownService = $this->sendAs('owner', 'PUT', $this->addressOf($services, $this->puraNavaja, $this->resources), ['servicios' => [(string) Str::uuid7()]]);
        $this->assertSame($unknownService->getStatusCode(), $foreignService->getStatusCode());
        $this->assertGreaterThanOrEqual(400, $foreignService->getStatusCode());
        $this->assertSame($this->problemOf($unknownService), $this->problemOf($foreignService));

        $foreignCollaborator = ['colaborador' => $this->mauricio];
        $this->sendAs('owner', 'PUT', $this->addressOf($services, $this->puraNavaja, $foreignCollaborator), ['servicios' => [$this->degradado]])->assertNotFound();
        $this->sendAs('owner', 'PUT', $this->addressOf($schedule, $this->puraNavaja, $foreignCollaborator), ['turnos' => [], 'pausas' => []])->assertNotFound();
        $this->sendAs('owner', 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/colaboradores/{colaborador}', $this->puraNavaja, $foreignCollaborator), ['activo' => false])->assertNotFound();
        $this->assertSame($before, $this->fingerprint());
    }

    /** The data of the business and the policy of payment keep the barbershop, the identifier, the link and the gateway the body tries to set. */
    public function test_the_business_and_the_payment_policy_ignore_the_protected_fields(): void
    {
        $original = (array) DB::table('barbershops')->where('id', $this->puraNavaja)->first();

        $this->sendAs('owner', 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/negocio', $this->puraNavaja, []), [
            'nombre' => 'Pura Navaja Renovada',
            'id' => $this->fadeLab,
            'enlace' => 'robado',
            'public_link' => 'robado',
            'dueno' => $this->bea,
            'owner_id' => $this->bea,
            'rol' => 'owner',
        ])->assertOk();
        $this->sendAs('owner', 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/cobro', $this->puraNavaja, []), [
            'plazo_cancelacion_horas' => 6,
            'id' => $this->fadeLab,
            'barberia' => $this->fadeLab,
        ])->assertOk();

        $changed = (array) DB::table('barbershops')->where('id', $this->puraNavaja)->first();
        $this->assertSame($this->puraNavaja, $changed['id']);
        $this->assertSame($original['public_link'], $changed['public_link']);
        $this->assertSame('Pura Navaja Renovada', $changed['name']);
        $this->assertSame(6, $changed['cancellation_deadline_hours']);
        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->bea)->where('barbershop_id', $this->puraNavaja)->count());

        $this->assertSame(1, DB::table('barbershops')->where('id', $this->fadeLab)->where('name', 'fade-lab')->count());
    }

    /** A collaborator cannot charge, refund, edit the payment policy or the data of the business, nor read a payment (CO-04, PA-08, UP-03). */
    public function test_a_collaborator_cannot_touch_money_or_the_business(): void
    {
        $before = $this->fingerprint();

        foreach (['collaborator', 'assigned_collaborator'] as $actor) {
            $this->sendAs($actor, 'POST', $this->addressOf('api/v1/barberias/{barberia}/reservas/{reserva}/pagos', $this->puraNavaja, $this->resources), ['metodo' => 'tarjeta', 'token' => 'pm_test_1', 'monto' => 1])->assertForbidden();
            $this->sendAs($actor, 'POST', $this->addressOf('api/v1/barberias/{barberia}/pagos/{pago}/reembolsos', $this->puraNavaja, $this->resources))->assertForbidden();
            $this->sendAs($actor, 'GET', $this->addressOf('api/v1/barberias/{barberia}/pagos/{pago}', $this->puraNavaja, $this->resources))->assertForbidden();
            $this->sendAs($actor, 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/cobro', $this->puraNavaja, []), ['modalidad' => 'total'])->assertForbidden();
            $this->sendAs($actor, 'PATCH', $this->addressOf('api/v1/barberias/{barberia}/administracion/negocio', $this->puraNavaja, []), ['nombre' => 'Mía'])->assertForbidden();
            $this->sendAs($actor, 'POST', $this->addressOf('api/v1/barberias/{barberia}/reservas/{reserva}/cancelacion', $this->puraNavaja, $this->resources))->assertForbidden();
        }

        $this->assertSame($before, $this->fingerprint());
    }

    /** A customer cannot read the agenda of the business, nor the reservation or the history of another, and sees their own without the history (CL-04, RE-09). */
    public function test_a_customer_sees_only_their_own_reservations(): void
    {
        $this->sendAs('customer', 'GET', $this->addressOf('api/v1/barberias/{barberia}/reservas', $this->puraNavaja, []))->assertForbidden();
        $this->sendAs('customer', 'GET', $this->addressOf('api/v1/barberias/{barberia}/reservas', $this->puraNavaja, []).'?colaborador='.$this->daniela)->assertForbidden();
        $this->sendAs('other_customer', 'GET', $this->addressOf('api/v1/barberias/{barberia}/reservas/{reserva}', $this->puraNavaja, $this->resources))->assertNotFound();

        $this->sendAs('customer', 'GET', $this->addressOf('api/v1/barberias/{barberia}/reservas/{reserva}', $this->puraNavaja, $this->resources))
            ->assertOk()
            ->assertJsonMissingPath('data.historial');
    }

    /** The owner of one barbershop cannot cancel, reschedule, pay or refund what belongs to the other, in either URL (MT-02, UP-06). */
    public function test_the_owner_of_one_barbershop_cannot_act_on_the_reservations_of_another(): void
    {
        $before = $this->fingerprint();
        $operations = [
            ['POST', 'api/v1/barberias/{barberia}/reservas/{reserva}/cancelacion', []],
            ['PATCH', 'api/v1/barberias/{barberia}/reservas/{reserva}', ['inicio' => '2030-03-05T14:00:00-06:00']],
            ['POST', 'api/v1/barberias/{barberia}/pagos/{pago}/reembolsos', []],
        ];

        foreach ([['owner', $this->foreignResources], ['foreign_owner', $this->resources]] as [$actor, $resources]) {
            foreach ($operations as [$method, $template, $payload]) {
                foreach ([$this->puraNavaja, $this->fadeLab] as $barbershopId) {
                    $this->sendAs($actor, $method, $this->addressOf($template, $barbershopId, $resources), $payload)->assertNotFound();
                }
            }
        }

        $this->assertSame($before, $this->fingerprint());
    }

    /** The token of the owner of one barbershop reaches nothing in the other, and a revoked token reaches nothing at all. */
    public function test_a_token_of_one_barbershop_does_not_open_the_other(): void
    {
        $before = $this->fingerprint();

        foreach (AccessMatrix::rules() as $key => $rule) {
            if ($rule->kind === RouteRule::PUBLIC || $rule->kind === RouteRule::AUTHENTICATED || $rule->kind === RouteRule::FIRST_USE) {
                continue;
            }

            [$method, $template] = explode(' ', $key, 2);
            $this->sendAs('owner', $method, $this->addressOf($template, $this->fadeLab, $this->foreignResources), $this->payloadOf($key))
                ->assertNotFound();
        }

        $this->assertSame($before, $this->fingerprint());

        $this->sendAs('owner', 'DELETE', '/api/v1/tokens/actual')->assertNoContent();
        $this->sendAs('owner', 'GET', $this->addressOf('api/v1/barberias/{barberia}/administracion/servicios', $this->puraNavaja, []))->assertUnauthorized();
        $this->sendAs('owner', 'GET', '/api/v1/mis-barberias')->assertUnauthorized();
    }

    /** The barbershop of the URL decides: another one named in a header, the query or the body changes nothing (MT-03, RD-18). */
    public function test_the_barbershop_named_in_a_header_a_query_or_a_body_is_ignored(): void
    {
        $services = $this->addressOf('api/v1/barberias/{barberia}/administracion/servicios', $this->puraNavaja, []);
        $foreignServices = $this->addressOf('api/v1/barberias/{barberia}/administracion/servicios', $this->fadeLab, []);
        $names = ['X-Barberia', 'X-Barbershop', 'X-Barbershop-Id', 'X-Tenant', 'X-Tenant-Id'];
        $others = [...array_fill_keys($names, $this->fadeLab)];

        $own = $this->sendAs('owner', 'GET', $services, [], $others)->assertOk();
        $this->assertStringContainsString('Degradado', (string) $own->getContent());
        $this->assertStringNotContainsString('Corte', (string) $own->getContent());

        $own = $this->sendAs('owner', 'GET', "{$services}?barberia={$this->fadeLab}&barbershop_id={$this->fadeLab}")->assertOk();
        $this->assertStringNotContainsString('Corte', (string) $own->getContent());

        $missing = $this->sendAs('owner', 'GET', $this->addressOf('api/v1/barberias/{barberia}/administracion/servicios', (string) Str::uuid7(), []));
        $foreign = $this->sendAs('owner', 'GET', $foreignServices, [], array_fill_keys($names, $this->puraNavaja))->assertNotFound();
        $this->assertSame($this->problemOf($missing), $this->problemOf($foreign));
        $this->sendAs('owner', 'GET', "{$foreignServices}?barberia={$this->puraNavaja}")->assertNotFound();
    }

    /** The pages and the filters of the listings never mix rows of another barbershop or of another person (CO-04, CL-04, MT-02). */
    public function test_listings_never_mix_barbershops_or_people_whatever_the_page_or_the_filter(): void
    {
        $luisAtEleven = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->luisMembership, self::LUIS_AT_ELEVEN);
        $kevinForLuis = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->luisMembership, '2030-03-05T09:00:00-06:00');
        $kevinForAna = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaMembership, '2030-03-05T14:00:00-06:00');
        $anas = [$this->resources['reserva'], $kevinForAna];
        $all = [...$anas, $luisAtEleven, $kevinForLuis];
        $reservations = 'api/v1/barberias/{barberia}/reservas';
        $mine = 'api/v1/barberias/{barberia}/mis-reservas';
        $noise = "barberia={$this->fadeLab}&barbershop_id={$this->fadeLab}&cliente={$this->bea}&usuario={$this->luis}&membresia={$this->luisMembership}";

        // One row per page, with and without a filter that names someone else: the whole of the person and nothing more.
        $this->assertEqualsCanonicalizing($all, $this->allPages('owner', $reservations, $this->puraNavaja, $noise));
        $this->assertEqualsCanonicalizing([$this->foreignResources['reserva']], $this->allPages('foreign_owner', $reservations, $this->fadeLab, $noise));
        $this->assertEqualsCanonicalizing($anas, $this->allPages('customer', $mine, $this->puraNavaja, $noise));
        $this->assertEqualsCanonicalizing([$luisAtEleven, $kevinForLuis], $this->allPages('other_customer', $mine, $this->puraNavaja, $noise));
        $this->assertEqualsCanonicalizing([$kevinForLuis, $kevinForAna], $this->allPages('collaborator', $reservations, $this->puraNavaja, $noise));
        $this->assertEqualsCanonicalizing([$this->resources['reserva'], $luisAtEleven], $this->allPages('assigned_collaborator', $reservations, $this->puraNavaja, $noise));
        $this->assertEqualsCanonicalizing([], $this->allPages('foreign_owner', $mine, $this->puraNavaja, $noise));

        // The filter of the collaborator names no one else's agenda: another collaborator, or one of another barbershop, is a 404.
        $this->sendAs('collaborator', 'GET', $this->addressOf($reservations, $this->puraNavaja, []).'?colaborador='.$this->daniela)->assertNotFound();
        $this->sendAs('collaborator', 'GET', $this->addressOf($reservations, $this->puraNavaja, []).'?colaborador='.$this->mauricio)->assertNotFound();
        $this->sendAs('owner', 'GET', $this->addressOf($reservations, $this->puraNavaja, []).'?colaborador='.$this->mauricio)->assertNotFound();
    }

    /**
     * The identifiers of every page of a listing, one row per page, as a person with a filter that names someone else.
     *
     * @return list<string>
     */
    private function allPages(string $actor, string $template, string $barbershopId, string $noise): array
    {
        $ids = [];

        foreach (range(1, 12) as $page) {
            $response = $this->sendAs($actor, 'GET', $this->addressOf($template, $barbershopId, [])."?pagina={$page}&por_pagina=1&{$noise}")->assertOk();
            $rows = (array) $response->json('data.*.id');

            if ($rows === []) {
                break;
            }

            $ids = [...$ids, ...array_values(array_map('strval', $rows))];
        }

        $this->assertSame($ids, array_values(array_unique($ids)), 'A page repeats a row of another.');

        return $ids;
    }
}
