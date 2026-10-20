<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The middleware against the real memberships: Pura Navaja and Fade Lab, and the people who work in each (RF-44, UP-06). */
final class BarbershopMembershipIsolationTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string PREFIX = '/api/v1/pruebas/barberias/';

    private UserModel $ana;

    private UserModel $luis;

    private string $puraNavaja;

    private string $fadeLab;

    /** Two barbershops, an owner in each, and a throwaway route behind the middleware. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->ana = $this->createUser('ana@barberhub.test');
        $this->luis = $this->createUser('luis@barberhub.test');
        $this->createMembership($this->ana, $this->puraNavaja, 'owner', true);
        $this->createMembership($this->luis, $this->fadeLab, 'owner', true);

        Route::prefix('api/v1/pruebas')->middleware('api')->group(function (): void {
            Route::get('barberias/{barberia}/estado', fn (Request $request): array => [
                'context' => app(BarbershopContext::class)->current()->value,
                'role' => $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE),
                'setting' => DB::scalar("select current_setting('app.barbershop_id', true)"),
            ])->middleware(['auth:sanctum', 'barbershop'])->whereUuid('barberia');
        });
    }

    /** A person who works in a barbershop reaches it with their role. */
    public function test_member_reaches_their_barbershop(): void
    {
        Sanctum::actingAs($this->ana, []);

        $this->getJson(self::PREFIX.$this->puraNavaja.'/estado')->assertOk()->assertExactJson([
            'context' => $this->puraNavaja,
            'role' => 'owner',
            'setting' => $this->puraNavaja,
        ]);
    }

    /** Swapping the barbershop of the URL for Fade Lab shows and changes nothing: it answers like a barbershop that does not exist. */
    public function test_swapping_the_barbershop_for_another_one_is_not_found(): void
    {
        Sanctum::actingAs($this->ana, []);

        $swapped = $this->getJson(self::PREFIX.$this->fadeLab.'/estado')->assertNotFound()->json();
        $unknown = $this->getJson(self::PREFIX.(string) Str::uuid7().'/estado')->assertNotFound()->json();

        unset($swapped['instance'], $unknown['instance']);
        $this->assertSame($unknown, $swapped);
    }

    /** Each person reaches their own barbershop and not the other. */
    public function test_each_person_is_limited_to_their_barbershop(): void
    {
        Sanctum::actingAs($this->luis, []);

        $this->getJson(self::PREFIX.$this->fadeLab.'/estado')->assertOk()->assertJsonPath('context', $this->fadeLab);
        $this->getJson(self::PREFIX.$this->puraNavaja.'/estado')->assertNotFound();
    }

    /** A person with a role in two barbershops reaches both, each with its own role and context (UP-07). */
    public function test_person_with_roles_in_two_barbershops_gets_the_one_of_the_url(): void
    {
        $this->createMembership($this->ana, $this->fadeLab, 'customer', true);
        Sanctum::actingAs($this->ana, []);

        $this->getJson(self::PREFIX.$this->puraNavaja.'/estado')->assertOk()
            ->assertJsonPath('context', $this->puraNavaja)->assertJsonPath('role', 'owner');
        $this->getJson(self::PREFIX.$this->fadeLab.'/estado')->assertOk()
            ->assertJsonPath('context', $this->fadeLab)->assertJsonPath('role', 'customer');
    }

    /** A deactivated membership no longer opens the barbershop. */
    public function test_inactive_membership_is_not_found(): void
    {
        MembershipModel::query()->where('user_id', $this->ana->id)->update(['is_active' => false]);
        Sanctum::actingAs($this->ana, []);

        $this->getJson(self::PREFIX.$this->puraNavaja.'/estado')->assertNotFound();
    }

    /** Create an account. */
    private function createUser(string $email): UserModel
    {
        return UserModel::query()->create(['name' => 'Ana Mora', 'email' => $email, 'password' => 'hash']);
    }

    /** Create a barbershop and return its identifier. */
    private function createBarbershop(string $link): string
    {
        return BarbershopModel::query()->create([
            'name' => 'Barbería '.$link,
            'city' => 'San Carlos',
            'phone' => '2460-0000',
            'public_link' => $link,
        ])->id;
    }

    /** Give a person a role in a barbershop. */
    private function createMembership(UserModel $user, string $barbershopId, string $role, bool $active): void
    {
        MembershipModel::query()->create([
            'user_id' => $user->id,
            'barbershop_id' => $barbershopId,
            'role' => $role,
            'is_active' => $active,
        ]);
    }
}
