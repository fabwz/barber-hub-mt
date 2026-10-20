<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The owner creates, edits, deactivates and reactivates the services of the barbershop (RF-03, SV-01, SV-04, UP-03). */
final class ManageServicesTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $stranger;

    /** Two barbershops; Pura Navaja has an owner, a collaborator and a customer, and Fade Lab has its own owner. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->stranger = $this->createMember('dani@barberhub.test', null, null);
    }

    /** Without a session the three operations answer the unauthenticated problem. */
    public function test_administration_requires_a_session(): void
    {
        $this->getJson($this->url($this->puraNavaja))->assertUnauthorized()->assertJsonPath('type', $this->problem('no-autenticado'));
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertUnauthorized();
        $this->patchJson($this->url($this->puraNavaja, (string) Str::uuid7()), ['precio' => 7500])->assertUnauthorized();
    }

    /** The owner creates an active service and gets its price split into base and tax (RF-03, SV-02, RL-02). */
    public function test_owner_creates_a_service(): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        $id = $response->json('data.id');
        $response->assertExactJson(['data' => [
            'id' => $id,
            'nombre' => 'Corte clásico',
            'duracion_minutos' => 30,
            'precio' => 7000,
            'base' => 6195,
            'iva' => 805,
            'activo' => true,
        ]]);
        $response->assertHeader('Location', url($this->url($this->puraNavaja, $id)));
        $this->assertTrue(Str::isUuid($id, 7));

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('services')->sole();
        $this->assertSame([$this->puraNavaja, 'Corte clásico', 30, 7000, true], [$row->barbershop_id, $row->name, $row->duration_minutes, $row->price, $row->is_active]);
    }

    /** The shortest and the longest duration are accepted (SV-01). */
    public function test_the_limits_of_the_duration_are_accepted(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $this->payload(minutes: 10))->assertCreated()->assertJsonPath('data.duracion_minutos', 10);
        $this->postJson($this->url($this->puraNavaja), $this->payload(minutes: 180))->assertCreated()->assertJsonPath('data.duracion_minutos', 180);
    }

    /**
     * Durations out of 10 to 180 minutes or between steps of 5.
     *
     * @return array<string, array{int}>
     */
    public static function invalidDurations(): array
    {
        return ['7 minutes' => [7], '9 minutes' => [9], '12 minutes' => [12], '181 minutes' => [181], '185 minutes' => [185], '200 minutes' => [200], 'zero' => [0]];
    }

    /** A duration out of the rule is refused and the error names the field (SV-01). */
    #[DataProvider('invalidDurations')]
    public function test_invalid_duration_is_refused_naming_the_field(int $minutes): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $this->payload(minutes: $minutes))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrorFor('duracion_minutos', 'errores')
            ->assertJsonMissingPath('errores.precio');

        $this->assertSame(0, $this->countServices($this->puraNavaja));
    }

    /** A price of ₡0 or less is refused and the error names the field (SV-01). */
    public function test_price_that_is_not_above_zero_is_refused_naming_the_field(): void
    {
        Sanctum::actingAs($this->owner, []);

        foreach ([0, -7000] as $price) {
            $this->postJson($this->url($this->puraNavaja), $this->payload(price: $price))
                ->assertUnprocessable()
                ->assertJsonPath('type', $this->problem('datos-invalidos'))
                ->assertJsonValidationErrorFor('precio', 'errores')
                ->assertJsonMissingPath('errores.duracion_minutos');
        }

        $this->assertSame(0, $this->countServices($this->puraNavaja));
    }

    /** Missing, blank, oversized and non-integer data is refused with each field named. */
    public function test_missing_and_malformed_data_is_refused_naming_each_field(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'duracion_minutos', 'precio'], 'errores');
        $this->postJson($this->url($this->puraNavaja), ['nombre' => '   ', 'duracion_minutos' => 30, 'precio' => 7000])
            ->assertJsonValidationErrorFor('nombre', 'errores');
        $this->postJson($this->url($this->puraNavaja), ['nombre' => str_repeat('a', 121), 'duracion_minutos' => 30, 'precio' => 7000])
            ->assertJsonValidationErrorFor('nombre', 'errores');
        $this->postJson($this->url($this->puraNavaja), ['nombre' => 'Corte', 'duracion_minutos' => 30.5, 'precio' => '7.000,50'])
            ->assertJsonValidationErrors(['duracion_minutos', 'precio'], 'errores');

        $this->assertSame(0, $this->countServices($this->puraNavaja));
    }

    /** The message for the duration names the field and its limits in Spanish. */
    public function test_the_message_names_the_field_and_its_limits(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->assertSame(
            ['El campo duración en minutos debe estar entre 10 y 180.'],
            $this->postJson($this->url($this->puraNavaja), $this->payload(minutes: 185))->json('errores.duracion_minutos'),
        );
        $this->assertSame(
            ['El campo duración en minutos debe ser un múltiplo de 5.'],
            $this->postJson($this->url($this->puraNavaja), $this->payload(minutes: 12))->json('errores.duracion_minutos'),
        );
    }

    /** Raising the price from ₡7.000 to ₡7.500 changes only the price and its breakdown (RF-03). */
    public function test_owner_raises_the_price(): void
    {
        Sanctum::actingAs($this->owner, []);
        $id = $this->createService($this->puraNavaja);

        $this->patchJson($this->url($this->puraNavaja, $id), ['precio' => 7500])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $id,
                'nombre' => 'Corte clásico',
                'duracion_minutos' => 30,
                'precio' => 7500,
                'base' => 6637,
                'iva' => 863,
                'activo' => true,
            ]]);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(7500, DB::table('services')->where('id', $id)->value('price'));
    }

    /** The owner changes the name and the duration, and an empty body changes nothing. */
    public function test_owner_edits_the_name_and_the_duration(): void
    {
        Sanctum::actingAs($this->owner, []);
        $id = $this->createService($this->puraNavaja);

        $this->patchJson($this->url($this->puraNavaja, $id), [])->assertOk()->assertJsonPath('data.nombre', 'Corte clásico');
        $this->patchJson($this->url($this->puraNavaja, $id), ['nombre' => '  Corte moderno ', 'duracion_minutos' => 45])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Corte moderno')
            ->assertJsonPath('data.duracion_minutos', 45)
            ->assertJsonPath('data.precio', 7000);
    }

    /** An invalid change is refused naming the field and the service stays as it was. */
    public function test_invalid_change_is_refused_and_the_service_stays(): void
    {
        Sanctum::actingAs($this->owner, []);
        $id = $this->createService($this->puraNavaja);

        $this->patchJson($this->url($this->puraNavaja, $id), ['duracion_minutos' => 200, 'precio' => 0, 'activo' => 'quizá'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['duracion_minutos', 'precio', 'activo'], 'errores');

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('services')->where('id', $id)->sole();
        $this->assertSame([30, 7000, true], [$row->duration_minutes, $row->price, $row->is_active]);
    }

    /** The owner deactivates a service and reactivates it; the service is never deleted (SV-04). */
    public function test_owner_deactivates_and_reactivates_a_service(): void
    {
        Sanctum::actingAs($this->owner, []);
        $id = $this->createService($this->puraNavaja);

        $this->patchJson($this->url($this->puraNavaja, $id), ['activo' => false])->assertOk()->assertJsonPath('data.activo', false);
        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.activo', false);

        $this->patchJson($this->url($this->puraNavaja, $id), ['activo' => true])->assertOk()->assertJsonPath('data.activo', true);
        $this->assertSame(1, $this->countServices($this->puraNavaja));
    }

    /** There is no way to delete a service: the route does not exist (SV-04). */
    public function test_a_service_cannot_be_deleted(): void
    {
        Sanctum::actingAs($this->owner, []);
        $id = $this->createService($this->puraNavaja);

        $this->deleteJson($this->url($this->puraNavaja, $id))->assertStatus(405)->assertJsonPath('type', $this->problem('metodo-no-permitido'));
        $this->assertSame(1, $this->countServices($this->puraNavaja));
    }

    /** The owner lists every service of the barbershop, active or not, ordered by name, and none of another barbershop. */
    public function test_owner_lists_the_services_of_the_barbershop(): void
    {
        Sanctum::actingAs($this->owner, []);
        $corte = $this->createService($this->puraNavaja, 'Corte');
        $barba = $this->createService($this->puraNavaja, 'Barba', active: false);
        $this->createService($this->fadeLab, 'Afeitado de Fade Lab');

        $this->getJson($this->url($this->puraNavaja))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $barba)
            ->assertJsonPath('data.0.activo', false)
            ->assertJsonPath('data.1.id', $corte)
            ->assertJsonPath('data.1.activo', true)
            ->assertJsonPath('data.1.iva', 805);
    }

    /** A barbershop with no services lists an empty array. */
    public function test_owner_of_a_barbershop_without_services_gets_an_empty_list(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertExactJson(['data' => []]);
    }

    /** A collaborator or a customer of the barbershop is refused with 403 "prohibido" in the three operations (UP-03). */
    public function test_a_member_who_is_not_the_owner_is_forbidden(): void
    {
        $id = $this->createService($this->puraNavaja);

        foreach ([$this->collaborator, $this->customer] as $member) {
            Sanctum::actingAs($member, []);

            $this->getJson($this->url($this->puraNavaja))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->postJson($this->url($this->puraNavaja), $this->payload())->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->patchJson($this->url($this->puraNavaja, $id), ['precio' => 1])->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
        }

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('services')->sole();
        $this->assertSame(7000, $row->price);
    }

    /** A person without a role in the barbershop gets the 404 of a barbershop that does not exist, not a 403 (UP-06). */
    public function test_a_person_without_a_role_in_the_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->stranger, []);
        $id = $this->createService($this->puraNavaja);

        $expected = $this->getJson($this->url((string) Str::uuid7()))->assertNotFound()->json();
        unset($expected['instance']);

        foreach ([
            $this->getJson($this->url($this->puraNavaja)),
            $this->postJson($this->url($this->puraNavaja), $this->payload()),
            $this->patchJson($this->url($this->puraNavaja, $id), ['precio' => 1]),
        ] as $response) {
            $body = $response->assertNotFound()->json();
            unset($body['instance']);
            $this->assertSame($expected, $body);
        }

        $this->assertSame(1, $this->countServices($this->puraNavaja));
    }

    /** The owner of one barbershop cannot administer another one, nor a service of another one (UP-06, MT-04). */
    public function test_the_owner_of_another_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->owner, []);
        $other = $this->createService($this->fadeLab, 'Corte de Fade Lab');

        $this->getJson($this->url($this->fadeLab))->assertNotFound();
        $this->postJson($this->url($this->fadeLab), $this->payload())->assertNotFound();
        $this->patchJson($this->url($this->fadeLab, $other), ['precio' => 1])->assertNotFound();
        $this->patchJson($this->url($this->puraNavaja, $other), ['precio' => 1])->assertNotFound()->assertJsonPath('detail', 'Este servicio no existe');

        $this->assertSame([7000], $this->pricesOf($this->fadeLab));
        $this->assertSame(0, $this->countServices($this->puraNavaja));
    }

    /** A service that does not exist, and an identifier that is not one, are not found. */
    public function test_unknown_service_is_not_found(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja, (string) Str::uuid7()), ['precio' => 7500])
            ->assertNotFound()
            ->assertJsonPath('type', $this->problem('no-encontrado'));
        $this->patchJson($this->url($this->puraNavaja, 'no-es-un-uuid'), ['precio' => 7500])->assertNotFound();
    }

    /** The administration routes pass through the session, the barbershop and the role of the owner, in that order. */
    public function test_routes_are_guarded_in_order(): void
    {
        foreach (['catalog.services.index', 'catalog.services.store', 'catalog.services.update'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame(['auth:sanctum', 'barbershop', 'role:owner'], array_values(array_filter(
                $route->gatherMiddleware(),
                fn (mixed $middleware): bool => is_string($middleware) && in_array($middleware, ['auth:sanctum', 'barbershop', 'role:owner'], true),
            )));
        }
    }

    /** Address of the services the owner administers, or of one of them. */
    private function url(string $barbershopId, ?string $serviceId = null): string
    {
        $url = "/api/v1/barberias/{$barbershopId}/administracion/servicios";

        return $serviceId === null ? $url : "{$url}/{$serviceId}";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /**
     * A valid body, overriding only what a scenario changes.
     *
     * @return array{nombre: string, duracion_minutos: int, precio: int}
     */
    private function payload(int $minutes = 30, int $price = 7000): array
    {
        return ['nombre' => 'Corte clásico', 'duracion_minutos' => $minutes, 'precio' => $price];
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Create a person, with a role in a barbershop unless none is given. */
    private function createMember(string $email, ?string $barbershopId, ?string $role): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => 'hash']);

        if ($barbershopId !== null) {
            MembershipModel::query()->create(['user_id' => $user->id, 'barbershop_id' => $barbershopId, 'role' => $role, 'is_active' => true]);
        }

        return $user;
    }

    /** Insert a service of a barbershop straight into the table and return its identifier. */
    private function createService(string $barbershopId, string $name = 'Corte clásico', bool $active = true): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => 30, 'price' => 7000, 'is_active' => $active]);

        return $id;
    }

    /** Number of services a barbershop has, read as the barbershop. */
    private function countServices(string $barbershopId): int
    {
        $this->scopeQueriesTo($barbershopId);

        return DB::table('services')->count();
    }

    /**
     * Prices of the services of a barbershop, read as the barbershop.
     *
     * @return list<int>
     */
    private function pricesOf(string $barbershopId): array
    {
        $this->scopeQueriesTo($barbershopId);

        return array_values(array_map(intval(...), DB::table('services')->pluck('price')->all()));
    }
}
