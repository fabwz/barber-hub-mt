<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * Each role reads the reservations of its scope (RF-13): the customer their own, the collaborator those of their agenda
 * and the owner those of the four collaborators of Pura Navaja, and nobody those of Fade Lab (CL-04, CO-04, UP-02, UP-06, MT-02).
 */
final class ListReservationsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $luis;

    private string $sofia;

    private string $mario;

    /** @var array<string, string> The reservations of Pura Navaja by name, from the earliest start to the latest. */
    private array $reservations = [];

    private string $fadeLabReservation;

    private string $outsider;

    /**
     * Pura Navaja has six reservations on four collaborators: Ana has three and Luis three, one of them at 23:30 of the 5th
     * (already the 6th in UTC). Fade Lab has one, of an account that is also a customer of Pura Navaja.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        $this->sofia = $this->insertCollaborator($this->puraNavaja, 'Sofía Vargas', [$this->degradado], [[2, '09:00', '19:00']]);
        $this->mario = $this->insertCollaborator($this->puraNavaja, 'Mario Brenes', [$this->degradado], [[2, '09:00', '19:00']]);
        [$this->ana, $anaHere] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [$this->luis, $luisHere] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $this->outsider = $this->insertUser('Persona Nueva');

        $this->reservations = [
            'first' => $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $anaHere, '2030-03-05T10:00:00-06:00'),
            'second' => $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $luisHere, '2030-03-05T11:00:00-06:00'),
            'late' => $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $luisHere, '2030-03-05T23:30:00-06:00', minutes: 20),
            'third' => $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $anaHere, '2030-03-06T09:00:00-06:00', 'completed'),
            'fourth' => $this->insertReservation($this->puraNavaja, $this->sofia, $this->degradado, $luisHere, '2030-03-06T10:00:00-06:00', 'cancelled_by_customer'),
            'fifth' => $this->insertReservation($this->puraNavaja, $this->mario, $this->degradado, $anaHere, '2030-03-07T09:00:00-06:00'),
        ];

        $this->scopeQueriesTo($this->fadeLab);
        $anaThere = (string) Str::uuid7();
        DB::table('memberships')->insert(['id' => $anaThere, 'user_id' => $this->ana, 'barbershop_id' => $this->fadeLab, 'role' => 'customer']);
        $this->fadeLabReservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $anaThere, '2030-03-05T10:00:00-06:00', minutes: 30);
    }

    /** A customer sees only their own reservations, from the earliest start, with the names of the service and the barber and nothing about other customers (CL-04). */
    public function test_a_customer_sees_only_their_own_reservations(): void
    {
        $this->actAs($this->ana);

        $response = $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk();

        $this->assertSame([$this->reservations['first'], $this->reservations['third'], $this->reservations['fifth']], $response->json('data.*.id'));
        $response->assertJsonPath('meta', ['pagina' => 1, 'por_pagina' => 15, 'total' => 3, 'ultima_pagina' => 1])
            ->assertJsonPath('data.0.servicio', $this->degradado)
            ->assertJsonPath('data.0.servicio_nombre', 'Degradado')
            ->assertJsonPath('data.0.barbero', $this->daniela)
            ->assertJsonPath('data.0.barbero_nombre', 'Daniela')
            ->assertJsonPath('data.0.estado', 'confirmada')
            ->assertJsonPath('data.0.inicio', '2030-03-05T10:00:00-06:00')
            ->assertJsonPath('data.0.fin', '2030-03-05T10:45:00-06:00')
            ->assertJsonPath('data.0.precio', 7000)
            ->assertJsonPath('data.0.duracion_minutos', 45)
            ->assertJsonPath('data.1.estado', 'completada')
            ->assertJsonMissingPath('data.0.cliente_nombre');
        $this->assertStringNotContainsString($this->fadeLabReservation, (string) $response->getContent());
        $this->assertStringNotContainsString($this->reservations['second'], (string) $response->getContent());
    }

    /** Each customer reads their own list: Luis gets his three and none of Ana's. */
    public function test_another_customer_sees_theirs(): void
    {
        $this->actAs($this->luis);

        $this->assertSame(
            [$this->reservations['second'], $this->reservations['late'], $this->reservations['fourth']],
            $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk()->json('data.*.id'),
        );
    }

    /** A person without a role in the barbershop gets an empty list, not a 404, and no role is created by asking (RD-18, UP-09). */
    public function test_a_person_without_a_role_gets_an_empty_list(): void
    {
        $this->actAs($this->outsider);

        $this->getJson($this->myReservationsUrl($this->puraNavaja))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta', ['pagina' => 1, 'por_pagina' => 15, 'total' => 0, 'ultima_pagina' => 1]);

        $this->assertSame(0, DB::table('memberships')->where('user_id', $this->outsider)->count());
    }

    /** The owner or a collaborator of the barbershop who asks for their reservations as a customer gets the usual 403 (UP-09). */
    public function test_staff_asking_as_a_customer_is_refused(): void
    {
        foreach ([$this->owner, $this->userOf($this->kevin)] as $staff) {
            $this->actAs($staff);

            $this->getJson($this->myReservationsUrl($this->puraNavaja))
                ->assertForbidden()
                ->assertJsonPath('type', 'http://localhost/problemas/prohibido');
        }
    }

    /** Without a session both listings answer 401. */
    public function test_without_a_session_both_listings_answer_401(): void
    {
        $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertUnauthorized();
        $this->getJson($this->reservationsUrl($this->puraNavaja))->assertUnauthorized();
    }

    /** The owner sees the reservations of the four collaborators of the barbershop, with the name of each customer, and none of Fade Lab (RF-13, MT-02). */
    public function test_the_owner_sees_every_collaborator_and_nothing_of_another_barbershop(): void
    {
        $this->actAs($this->owner);

        $response = $this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk();

        $this->assertSame(array_values($this->reservations), $response->json('data.*.id'));
        $this->assertEqualsCanonicalizing(
            [$this->daniela, $this->kevin, $this->sofia, $this->mario],
            array_values(array_unique($response->json('data.*.barbero'))),
        );
        $this->assertSame(['Ana Rojas', 'Luis Mora', 'Luis Mora', 'Ana Rojas', 'Luis Mora', 'Ana Rojas'], $response->json('data.*.cliente_nombre'));
        $response->assertJsonPath('meta.total', 6)
            ->assertJsonPath('data.3.barbero_nombre', 'Kevin')
            ->assertJsonPath('data.4.estado', 'cancelada_por_cliente');
        $this->assertStringNotContainsString($this->fadeLabReservation, (string) $response->getContent());
    }

    /** The staff listing carries the name of the customer and no way to reach them: no email, no phone (CO-04). */
    public function test_the_listing_does_not_expose_contact_data(): void
    {
        $this->actAs($this->owner);

        $response = $this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk();

        $this->assertStringNotContainsString('@barberhub.test', (string) $response->getContent());
        $keys = array_keys($response->json('data.0'));
        sort($keys);
        $this->assertSame(
            ['barbero', 'barbero_nombre', 'cliente_nombre', 'duracion_minutos', 'estado', 'fin', 'id', 'inicio', 'precio', 'servicio', 'servicio_nombre'],
            $keys,
        );
    }

    /** The owner narrows to one collaborator; a collaborator that is not in the barbershop is as missing as one that does not exist (UP-06). */
    public function test_the_owner_filters_by_collaborator(): void
    {
        $this->actAs($this->owner);

        $this->assertSame(
            [$this->reservations['third']],
            $this->getJson($this->reservationsUrl($this->puraNavaja).'?colaborador='.$this->kevin)->assertOk()->json('data.*.id'),
        );

        $other = $this->getJson($this->reservationsUrl($this->puraNavaja).'?colaborador='.$this->mauricio)->assertNotFound();
        $missing = $this->getJson($this->reservationsUrl($this->puraNavaja).'?colaborador='.Str::uuid7())->assertNotFound();
        $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($other->json()));
    }

    /** A collaborator sees only their own agenda (CO-04). */
    public function test_a_collaborator_sees_only_their_own_agenda(): void
    {
        $this->actAs($this->userOf($this->daniela));

        $response = $this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk();

        $this->assertSame([$this->reservations['first'], $this->reservations['second'], $this->reservations['late']], $response->json('data.*.id'));
        $response->assertJsonPath('meta.total', 3);
    }

    /** Kevin asks for Daniela's agenda and gets exactly what a collaborator that does not exist gets: a 404, with the same body (UP-06, RF-13). */
    public function test_a_collaborator_asking_for_another_agenda_gets_the_not_found_of_a_missing_one(): void
    {
        $this->actAs($this->userOf($this->kevin));

        $daniela = $this->getJson($this->reservationsUrl($this->puraNavaja).'?colaborador='.$this->daniela)->assertNotFound();
        $missing = $this->getJson($this->reservationsUrl($this->puraNavaja).'?colaborador='.Str::uuid7())->assertNotFound();

        $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($daniela->json()));
        $this->assertSame('http://localhost/problemas/no-encontrado', $daniela->json('type'));
        $this->assertStringNotContainsString($this->reservations['first'], (string) $daniela->getContent());
    }

    /** A collaborator may name their own profile; it is the same as not naming one. */
    public function test_a_collaborator_may_ask_for_their_own_profile(): void
    {
        $this->actAs($this->userOf($this->kevin));

        $this->assertSame(
            [$this->reservations['third']],
            $this->getJson($this->reservationsUrl($this->puraNavaja).'?colaborador='.$this->kevin)->assertOk()->json('data.*.id'),
        );
    }

    /** An owner with an agenda of their own keeps both views: all the reservations, and their own by naming their profile. */
    public function test_an_owner_who_is_also_a_collaborator_keeps_both_views(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $profile = (string) Str::uuid7();
        DB::table('collaborators')->insert(['id' => $profile, 'barbershop_id' => $this->puraNavaja, 'user_id' => $this->owner]);
        $own = $this->insertReservation($this->puraNavaja, $profile, $this->degradado, $this->customerRole($this->ana), '2030-03-08T09:00:00-06:00');
        $this->actAs($this->owner);

        $this->assertSame([...array_values($this->reservations), $own], $this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk()->json('data.*.id'));
        $this->assertSame([$own], $this->getJson($this->reservationsUrl($this->puraNavaja).'?colaborador='.$profile)->assertOk()->json('data.*.id'));
        $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertForbidden();
    }

    /** A customer of the barbershop asking for the staff listing gets 403; a person without a role or from another barbershop, the 404 of a barbershop that is not theirs (UP-06). */
    public function test_the_staff_listing_refuses_a_customer_and_hides_the_barbershop_from_outsiders(): void
    {
        $this->actAs($this->ana);
        $this->getJson($this->reservationsUrl($this->puraNavaja))->assertForbidden();

        $unknown = $this->getJson($this->reservationsUrl((string) Str::uuid7()))->assertNotFound();

        foreach ([$this->outsider, $this->userOf($this->mauricio)] as $person) {
            $this->actAs($person);
            $response = $this->getJson($this->reservationsUrl($this->puraNavaja))->assertNotFound();
            $this->assertSame($this->withoutInstance($unknown->json()), $this->withoutInstance($response->json()));
        }
    }

    /** The owner of Fade Lab sees its single reservation and none of Pura Navaja. */
    public function test_the_other_barbershop_sees_only_its_own(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $fadeOwner = $this->insertUser('Dueño de Fade Lab');
        $this->grantRole($fadeOwner, $this->fadeLab, 'owner');
        $this->actAs($fadeOwner);

        $this->assertSame([$this->fadeLabReservation], $this->getJson($this->reservationsUrl($this->fadeLab))->assertOk()->json('data.*.id'));
    }

    /** The day is a wall date of Costa Rica: the reservation at 23:30 of the 5th is of the 5th although it is already the 6th in UTC. */
    public function test_the_day_filter_uses_the_wall_date_of_costa_rica(): void
    {
        $this->actAs($this->owner);

        $this->assertSame(
            [$this->reservations['first'], $this->reservations['second'], $this->reservations['late']],
            $this->getJson($this->reservationsUrl($this->puraNavaja).'?dia=2030-03-05')->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$this->reservations['third'], $this->reservations['fourth']],
            $this->getJson($this->reservationsUrl($this->puraNavaja).'?dia=2030-03-06')->assertOk()->json('data.*.id'),
        );
        $this->getJson($this->reservationsUrl($this->puraNavaja).'?dia=2030-03-09')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    /** The status filter uses the names of the API and combines with the day and the collaborator. */
    public function test_the_status_filter_combines_with_the_others(): void
    {
        $this->actAs($this->owner);
        $url = $this->reservationsUrl($this->puraNavaja);

        $this->assertSame([$this->reservations['fourth']], $this->getJson($url.'?estado=cancelada_por_cliente')->assertOk()->json('data.*.id'));
        $this->assertSame([$this->reservations['third']], $this->getJson($url.'?estado=completada')->assertOk()->json('data.*.id'));
        $this->assertSame(
            [$this->reservations['first'], $this->reservations['second'], $this->reservations['late']],
            $this->getJson($url.'?estado=confirmada&dia=2030-03-05&colaborador='.$this->daniela)->assertOk()->json('data.*.id'),
        );

        $this->actAs($this->ana);
        $this->assertSame(
            [$this->reservations['first'], $this->reservations['fifth']],
            $this->getJson($this->myReservationsUrl($this->puraNavaja).'?estado=confirmada')->assertOk()->json('data.*.id'),
        );
        $this->assertSame(
            [$this->reservations['third']],
            $this->getJson($this->myReservationsUrl($this->puraNavaja).'?dia=2030-03-06')->assertOk()->json('data.*.id'),
        );
    }

    /** A filter that is not valid answers 422 with the field, and the customer listing does not accept a collaborator. */
    public function test_invalid_filters_are_rejected(): void
    {
        $this->actAs($this->owner);
        $url = $this->reservationsUrl($this->puraNavaja);

        $this->getJson($url.'?estado=pagada')->assertUnprocessable()->assertJsonPath('errores.estado.0', 'El valor seleccionado para estado no es válido.');
        $this->getJson($url.'?dia=2030-02-31')->assertUnprocessable()->assertJsonStructure(['errores' => ['dia']]);
        $this->getJson($url.'?dia=05/03/2030')->assertUnprocessable()->assertJsonStructure(['errores' => ['dia']]);
        $this->getJson($url.'?colaborador=Kevin')->assertUnprocessable()->assertJsonStructure(['errores' => ['colaborador']]);

        $this->actAs($this->ana);
        $this->assertCount(3, $this->getJson($this->myReservationsUrl($this->puraNavaja).'?colaborador='.$this->kevin)->assertOk()->json('data'));
    }

    /** The identifier of the account of a collaborator. */
    private function userOf(string $collaboratorId): string
    {
        foreach ([$this->puraNavaja, $this->fadeLab] as $barbershopId) {
            $this->scopeQueriesTo($barbershopId);
            $userId = DB::table('collaborators')->where('id', $collaboratorId)->value('user_id');

            if (is_string($userId)) {
                return $userId;
            }
        }

        $this->fail('The collaborator is in no barbershop.');
    }

    /** The customer role of an account in Pura Navaja. */
    private function customerRole(string $userId): string
    {
        return (string) DB::table('memberships')->where('user_id', $userId)->where('barbershop_id', $this->puraNavaja)->value('id');
    }
}
