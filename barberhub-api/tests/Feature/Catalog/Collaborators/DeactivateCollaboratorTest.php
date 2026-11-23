<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Collaborators;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** A collaborator with confirmed reservations ahead is not deactivated until the owner resolves them (CO-03, UP-05). */
final class DeactivateCollaboratorTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;
    use SeedsReservations;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $kevinUser;

    private string $kevin;

    private string $daniela;

    private string $haircut;

    private string $customerMembership;

    /** Pura Navaja has its owner, Kevin and Daniela (collaborators), a service and a customer; Fade Lab is another barbershop. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->kevinUser = $this->createMember('kevin@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->kevin = $this->insertProfile($this->puraNavaja, $this->kevinUser);
        $this->daniela = $this->insertProfile($this->puraNavaja, $this->createMember('daniela@barberhub.test', $this->puraNavaja, 'collaborator'));

        $this->scopeQueriesTo($this->puraNavaja);
        $this->haircut = $this->insertService($this->puraNavaja, 'Corte clásico');
        $customer = $this->insertCustomer($this->puraNavaja, 'Ana Quirós');
        $this->customerMembership = $customer[1];
        DB::table('users')->where('id', $customer[0])->update(['name' => 'Ana Quirós']);
    }

    /** One confirmed reservation ahead blocks it: 409 `colaborador-con-reservas` with the list, and nothing changes. */
    public function test_a_confirmed_reservation_ahead_blocks_the_deactivation(): void
    {
        $soon = $this->reserve($this->kevin, now()->addDays(2)->toIso8601String());
        $later = $this->reserve($this->kevin, now()->addDays(5)->toIso8601String());
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => false])
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('colaborador-con-reservas'))
            ->assertJsonPath('status', 409)
            ->assertJsonPath('total_reservas', 2)
            ->assertJsonCount(2, 'reservas')
            ->assertJsonPath('reservas.0.id', $soon)
            ->assertJsonPath('reservas.1.id', $later)
            ->assertJsonPath('reservas.0.servicio', 'Corte clásico')
            ->assertJsonPath('reservas.0.cliente', 'Ana Quirós')
            ->assertJsonStructure(['reservas' => [['id', 'inicio', 'servicio', 'cliente']], 'detail']);

        // Nothing was written: still active, and the collaborator keeps their role.
        $this->getJson($this->url($this->puraNavaja, $this->kevin))->assertOk()->assertJsonPath('data.activo', true);
        $this->assertTrue(MembershipModel::query()->where('user_id', $this->kevinUser->id)->sole()->is_active);
    }

    /** Past, cancelled, completed and other people's reservations do not block. */
    public function test_reservations_that_do_not_count_do_not_block(): void
    {
        $this->reserve($this->kevin, now()->subDays(2)->toIso8601String());
        $this->reserve($this->kevin, now()->addDays(2)->toIso8601String(), 'cancelled_by_customer');
        $this->reserve($this->kevin, now()->addDays(3)->toIso8601String(), 'cancelled_by_barbershop');
        $this->reserve($this->kevin, now()->addDays(4)->toIso8601String(), 'completed');
        // Daniela has one ahead, which is nothing to do with Kevin.
        $this->reserve($this->daniela, now()->addDays(2)->toIso8601String());
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', false);
        $this->patchJson($this->url($this->puraNavaja, $this->daniela), ['activo' => false])->assertConflict();
    }

    /** Once the owner resolves the reservation, the collaborator can be deactivated. */
    public function test_after_cancelling_the_reservation_the_collaborator_can_be_deactivated(): void
    {
        $reservation = $this->reserve($this->kevin, now()->addDays(2)->toIso8601String());
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => false])->assertConflict();

        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('reservations')->where('id', $reservation)->update(['status' => 'cancelled_by_barbershop']);

        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => false])->assertOk()->assertJsonPath('data.activo', false);
    }

    /** Activating is always allowed, with or without reservations. */
    public function test_reactivating_is_always_allowed(): void
    {
        $this->reserve($this->kevin, now()->addDays(2)->toIso8601String(), 'confirmed');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('collaborators')->where('id', $this->kevin)->update(['is_active' => false]);
        Sanctum::actingAs($this->owner, []);

        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => true])->assertOk()->assertJsonPath('data.activo', true);
    }

    /** The profile of the owner is refused like any other and the owner keeps their membership (UP-05). */
    public function test_the_owner_profile_with_reservations_is_refused_and_the_owner_keeps_the_role(): void
    {
        Sanctum::actingAs($this->owner, []);
        $profile = (string) $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))->assertCreated()->json('data.id');
        $this->reserve($profile, now()->addDays(2)->toIso8601String());

        $this->patchJson($this->url($this->puraNavaja, $profile), ['activo' => false])->assertConflict();

        $membership = MembershipModel::query()->where('user_id', $this->owner->id)->where('barbershop_id', $this->puraNavaja)->sole();
        $this->assertSame(['owner', true], [$membership->role, $membership->is_active]);
        $this->getJson($this->url($this->puraNavaja))->assertOk();
    }

    /** The owner is told apart in the list and in the single read (CO-02). */
    public function test_the_response_says_which_collaborator_is_the_owner(): void
    {
        Sanctum::actingAs($this->owner, []);
        $profile = (string) $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))->assertCreated()->assertJsonPath('data.es_dueno', true)->json('data.id');

        $list = $this->getJson($this->url($this->puraNavaja))->assertOk()->assertJsonCount(3, 'data');
        $items = $list->json('data');
        $this->assertIsArray($items);
        $owners = [];
        foreach ($items as $item) {
            $this->assertIsArray($item);
            $owners[(string) $item['id']] = $item['es_dueno'];
        }
        $this->assertEquals([$this->kevin => false, $this->daniela => false, $profile => true], $owners);
        $this->getJson($this->url($this->puraNavaja, $this->kevin))->assertOk()->assertJsonPath('data.es_dueno', false);
        $this->getJson($this->url($this->puraNavaja, $profile))->assertOk()->assertJsonPath('data.es_dueno', true);
    }

    /** The owner of another barbershop finds nothing, even for a collaborator with reservations. */
    public function test_the_owner_of_another_barbershop_finds_nothing(): void
    {
        $this->reserve($this->kevin, now()->addDays(2)->toIso8601String());
        $otherOwner = UserModel::query()->where('email', 'luis@barberhub.test')->sole();
        Sanctum::actingAs($otherOwner, []);

        $this->patchJson($this->url($this->fadeLab, $this->kevin), ['activo' => false])->assertNotFound();
        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => false])->assertNotFound();

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertTrue((bool) DB::table('collaborators')->where('id', $this->kevin)->value('is_active'));
    }

    /** A collaborator, even the one with the reservations, cannot deactivate anyone. */
    public function test_a_collaborator_is_forbidden(): void
    {
        $this->reserve($this->kevin, now()->addDays(2)->toIso8601String());
        Sanctum::actingAs($this->kevinUser, []);

        $this->patchJson($this->url($this->puraNavaja, $this->daniela), ['activo' => false])->assertForbidden();
        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => false])->assertForbidden();
    }

    /** The reservation does not leak across barbershops: the list only has those of the barbershop of the route. */
    public function test_the_reservations_of_another_barbershop_are_never_listed(): void
    {
        $luis = UserModel::query()->where('email', 'luis@barberhub.test')->sole();
        $otherCollaborator = $this->insertProfile($this->fadeLab, $this->createMember('otro@barberhub.test', $this->fadeLab, 'collaborator'));
        $otherService = $this->insertService($this->fadeLab, 'Fade');
        $otherCustomer = $this->insertCustomer($this->fadeLab, 'Cliente de Fade Lab');
        $this->insertReservation($this->fadeLab, $otherCollaborator, $otherService, $otherCustomer[1], now()->addDays(2)->toIso8601String());

        Sanctum::actingAs($luis, []);
        $this->patchJson($this->url($this->fadeLab, $otherCollaborator), ['activo' => false])
            ->assertConflict()
            ->assertJsonPath('total_reservas', 1)
            ->assertJsonPath('reservas.0.servicio', 'Fade')
            ->assertJsonPath('reservas.0.cliente', 'Cliente de Fade Lab');

        // Pura Navaja's collaborator is not affected by it.
        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja, $this->kevin), ['activo' => false])->assertOk();
    }

    /** Insert a confirmed (or other status) reservation of a collaborator of Pura Navaja and return its identifier. */
    private function reserve(string $collaborator, string $startsAt, string $status = 'confirmed'): string
    {
        return $this->insertReservation($this->puraNavaja, $collaborator, $this->haircut, $this->customerMembership, $startsAt, $status);
    }

    /** Address of the collaborators the owner administers, or of a path inside them. */
    private function url(string $barbershopId, ?string $path = null): string
    {
        $url = "/api/v1/barberias/{$barbershopId}/administracion/colaboradores";

        return $path === null ? $url : "{$url}/{$path}";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    private function createMember(string $email, string $barbershopId, string $role): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => 'hash']);
        MembershipModel::query()->create(['user_id' => $user->id, 'barbershop_id' => $barbershopId, 'role' => $role, 'is_active' => true]);

        return $user;
    }

    /** Insert the collaborator profile of an account and return its identifier. */
    private function insertProfile(string $barbershopId, UserModel $user): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $user->id]);

        return $id;
    }

    private function insertService(string $barbershopId, string $name): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => 30, 'price' => 7000]);

        return $id;
    }

    /** Insert an account, for the helpers that seed customers. */
    private function insertUser(string $name): string
    {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'name' => $name, 'email' => "{$id}@barberhub.test", 'password' => 'hash']);

        return $id;
    }
}
