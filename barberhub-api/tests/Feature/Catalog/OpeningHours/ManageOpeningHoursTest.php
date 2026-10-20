<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\OpeningHours;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The owner defines the weekly schedule of the barbershop in slots per day (RF-04, HO-01, UP-03). */
final class ManageOpeningHoursTest extends TestCase
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
        $this->deleteJson($this->url($this->puraNavaja, (string) Str::uuid7()))->assertUnauthorized();
    }

    /** The owner adds a slot and gets it back with the hours written as HH:MM (RF-04). */
    public function test_owner_adds_a_slot(): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        $id = $response->json('data.id');
        $response->assertExactJson(['data' => ['id' => $id, 'dia' => 1, 'abre' => '09:00', 'cierra' => '12:00']]);
        $this->assertTrue(Str::isUuid($id, 7));

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('opening_hours')->sole();
        $this->assertSame([$this->puraNavaja, 1, '09:00:00', '12:00:00'], [$row->barbershop_id, $row->day_of_week, $row->opens_at, $row->closes_at]);
    }

    /** A second slot overlapping the first on the same day answers 409 and is not stored (RF-04). */
    public function test_overlapping_slot_is_refused(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        foreach ([['11:59', '14:00'], ['08:00', '09:01'], ['10:00', '11:00'], ['09:00', '12:00']] as [$opens, $closes]) {
            $this->postJson($this->url($this->puraNavaja), $this->payload(opens: $opens, closes: $closes))
                ->assertConflict()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', $this->problem('conflicto'))
                ->assertJsonPath('detail', 'La franja se traslapa con otra del mismo día.');
        }

        $this->assertSame(1, $this->countSlots($this->puraNavaja));
    }

    /** Contiguous slots, 09:00-12:00 and 12:00-15:00, are accepted (HO-01). */
    public function test_contiguous_slots_are_accepted(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();
        $this->postJson($this->url($this->puraNavaja), $this->payload(opens: '12:00', closes: '15:00'))->assertCreated();
        $this->postJson($this->url($this->puraNavaja), $this->payload(day: 2))->assertCreated();

        $this->assertSame(3, $this->countSlots($this->puraNavaja));
    }

    /**
     * Bodies the request refuses, with the fields the error must name.
     *
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidBodies(): array
    {
        return [
            'empty body' => [[], ['dia', 'abre', 'cierra']],
            'day 0' => [['dia' => 0, 'abre' => '09:00', 'cierra' => '12:00'], ['dia']],
            'day 8' => [['dia' => 8, 'abre' => '09:00', 'cierra' => '12:00'], ['dia']],
            'day as text' => [['dia' => 'lunes', 'abre' => '09:00', 'cierra' => '12:00'], ['dia']],
            'hour 24' => [['dia' => 1, 'abre' => '24:00', 'cierra' => '12:00'], ['abre']],
            'without leading zero' => [['dia' => 1, 'abre' => '9:00', 'cierra' => '12:00'], ['abre']],
            'with seconds' => [['dia' => 1, 'abre' => '09:00:00', 'cierra' => '12:00'], ['abre']],
            'closing equal to the opening' => [['dia' => 1, 'abre' => '09:00', 'cierra' => '09:00'], ['cierra']],
            'closing before the opening' => [['dia' => 1, 'abre' => '12:00', 'cierra' => '09:00'], ['cierra']],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $fields
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_slot_is_refused_naming_the_fields(array $body, array $fields): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $body)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrors($fields, 'errores');

        $this->assertSame(0, $this->countSlots($this->puraNavaja));
    }

    /** The owner lists the schedule ordered by day and opening time, and none of another barbershop. */
    public function test_owner_lists_the_schedule(): void
    {
        Sanctum::actingAs($this->owner, []);
        $afternoon = $this->createSlot($this->puraNavaja, 1, '13:00', '18:00');
        $morning = $this->createSlot($this->puraNavaja, 1, '09:00', '12:00');
        $tuesday = $this->createSlot($this->puraNavaja, 2, '09:00', '12:00');
        $this->createSlot($this->fadeLab, 1, '09:00', '12:00');

        $this->getJson($this->url($this->puraNavaja))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $morning, 'dia' => 1, 'abre' => '09:00', 'cierra' => '12:00'],
                ['id' => $afternoon, 'dia' => 1, 'abre' => '13:00', 'cierra' => '18:00'],
                ['id' => $tuesday, 'dia' => 2, 'abre' => '09:00', 'cierra' => '12:00'],
            ]]);
    }

    /** A barbershop without a schedule lists an empty array. */
    public function test_owner_of_a_barbershop_without_a_schedule_gets_an_empty_list(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->getJson($this->url($this->puraNavaja))->assertOk()->assertExactJson(['data' => []]);
    }

    /** The owner removes a slot and its hours are free again. */
    public function test_owner_deletes_a_slot(): void
    {
        Sanctum::actingAs($this->owner, []);
        $id = $this->createSlot($this->puraNavaja, 7, '09:00', '12:00');

        $this->deleteJson($this->url($this->puraNavaja, $id))->assertNoContent();

        $this->assertSame(0, $this->countSlots($this->puraNavaja));
        $this->postJson($this->url($this->puraNavaja), $this->payload(day: 7))->assertCreated();
    }

    /** A slot that does not exist, and an identifier that is not one, are not found. */
    public function test_unknown_slot_is_not_found(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->deleteJson($this->url($this->puraNavaja, (string) Str::uuid7()))->assertNotFound()->assertJsonPath('type', $this->problem('no-encontrado'));
        $this->deleteJson($this->url($this->puraNavaja, 'no-es-un-uuid'))->assertNotFound();
    }

    /** A collaborator or a customer of the barbershop is refused with 403 "prohibido" in the three operations (UP-03). */
    public function test_a_member_who_is_not_the_owner_is_forbidden(): void
    {
        $id = $this->createSlot($this->puraNavaja, 1, '09:00', '12:00');

        foreach ([$this->collaborator, $this->customer] as $member) {
            Sanctum::actingAs($member, []);

            $this->getJson($this->url($this->puraNavaja))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->postJson($this->url($this->puraNavaja), $this->payload(day: 3))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->deleteJson($this->url($this->puraNavaja, $id))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
        }

        $this->assertSame(1, $this->countSlots($this->puraNavaja));
    }

    /** A person without a role in the barbershop gets the 404 of a barbershop that does not exist, not a 403 (UP-06). */
    public function test_a_person_without_a_role_in_the_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->stranger, []);
        $id = $this->createSlot($this->puraNavaja, 1, '09:00', '12:00');

        $expected = $this->getJson($this->url((string) Str::uuid7()))->assertNotFound()->json();
        unset($expected['instance']);

        foreach ([
            $this->getJson($this->url($this->puraNavaja)),
            $this->postJson($this->url($this->puraNavaja), $this->payload(day: 3)),
            $this->deleteJson($this->url($this->puraNavaja, $id)),
        ] as $response) {
            $body = $response->assertNotFound()->json();
            unset($body['instance']);
            $this->assertSame($expected, $body);
        }

        $this->assertSame(1, $this->countSlots($this->puraNavaja));
    }

    /** The owner of one barbershop cannot administer another one, nor a slot of another one (UP-06, MT-04). */
    public function test_the_owner_of_another_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->owner, []);
        $other = $this->createSlot($this->fadeLab, 1, '09:00', '12:00');

        $this->getJson($this->url($this->fadeLab))->assertNotFound();
        $this->postJson($this->url($this->fadeLab), $this->payload())->assertNotFound();
        $this->deleteJson($this->url($this->fadeLab, $other))->assertNotFound();
        $this->deleteJson($this->url($this->puraNavaja, $other))->assertNotFound()->assertJsonPath('detail', 'Esta franja del horario no existe');

        $this->assertSame(1, $this->countSlots($this->fadeLab));
        $this->assertSame(0, $this->countSlots($this->puraNavaja));
    }

    /** The schedule has no public route, and the administration ones pass through the session, the barbershop and the role of the owner, in that order. */
    public function test_routes_are_guarded_in_order_and_none_is_public(): void
    {
        foreach (['catalog.opening-hours.index', 'catalog.opening-hours.store', 'catalog.opening-hours.destroy'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame(['auth:sanctum', 'barbershop', 'role:owner'], array_values(array_filter(
                $route->gatherMiddleware(),
                fn (mixed $middleware): bool => is_string($middleware) && in_array($middleware, ['auth:sanctum', 'barbershop', 'role:owner', 'barbershop:public'], true),
            )));
        }

        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/horario")->assertNotFound();
    }

    /** Address of the schedule the owner administers, or of one slot. */
    private function url(string $barbershopId, ?string $slotId = null): string
    {
        $url = "/api/v1/barberias/{$barbershopId}/administracion/horario";

        return $slotId === null ? $url : "{$url}/{$slotId}";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /**
     * A valid body, overriding only what a scenario changes.
     *
     * @return array{dia: int, abre: string, cierra: string}
     */
    private function payload(int $day = 1, string $opens = '09:00', string $closes = '12:00'): array
    {
        return ['dia' => $day, 'abre' => $opens, 'cierra' => $closes];
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

    /** Insert a slot of a barbershop straight into the table and return its identifier. */
    private function createSlot(string $barbershopId, int $day, string $opens, string $closes): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('opening_hours')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes]);

        return $id;
    }

    /** Number of slots a barbershop has, read as the barbershop. */
    private function countSlots(string $barbershopId): int
    {
        $this->scopeQueriesTo($barbershopId);

        return DB::table('opening_hours')->count();
    }
}
