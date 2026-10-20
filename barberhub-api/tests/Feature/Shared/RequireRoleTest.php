<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Tests\Support\Shared\InMemoryBarbershopRoles;
use Tests\TestCase;

final class RequireRoleTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-0000-7000-8000-0000000000aa';

    private const string PREFIX = '/api/v1/pruebas/barberias/'.self::BARBERSHOP_ID;

    private const array USERS = [
        'owner' => '0192f0c4-0000-7000-8000-0000000000a1',
        'collaborator' => '0192f0c4-0000-7000-8000-0000000000a2',
        'customer' => '0192f0c4-0000-7000-8000-0000000000a3',
    ];

    /** One person per role, and throwaway routes behind the barbershop middleware and the role middleware. */
    protected function setUp(): void
    {
        parent::setUp();

        $roles = new InMemoryBarbershopRoles;

        foreach (self::USERS as $role => $userId) {
            $roles->grant($userId, self::BARBERSHOP_ID, $role);
        }

        $this->app->instance(BarbershopRoles::class, $roles);

        Route::prefix('api/v1/pruebas')->middleware('api')->group(function (): void {
            Route::get('barberias/{barberia}/dueno', fn (): array => ['ok' => true])
                ->middleware(['auth:sanctum', 'barbershop', 'role:owner'])->whereUuid('barberia');
            Route::get('barberias/{barberia}/personal', fn (): array => ['ok' => true])
                ->middleware(['auth:sanctum', 'barbershop', 'role:owner,collaborator'])->whereUuid('barberia');
            Route::get('publico/{barberia}/dueno', fn (): array => ['ok' => true])
                ->middleware(['barbershop:public', 'role:owner'])->whereUuid('barberia');
        });
    }

    /** The role that is allowed goes through. */
    public function test_allowed_role_goes_through(): void
    {
        $this->actAs('owner');

        $this->getJson(self::PREFIX.'/dueno')->assertOk()->assertExactJson(['ok' => true]);
    }

    /** Several roles can be allowed in the same route. */
    public function test_each_of_several_allowed_roles_goes_through(): void
    {
        foreach (['owner', 'collaborator'] as $role) {
            $this->actAs($role);

            $this->getJson(self::PREFIX.'/personal')->assertOk();
        }
    }

    /** A role that is not allowed gets 403 "prohibido" and the route does not run. */
    public function test_a_role_that_is_not_allowed_is_forbidden(): void
    {
        foreach (['collaborator', 'customer'] as $role) {
            $this->actAs($role);

            $this->getJson(self::PREFIX.'/dueno')
                ->assertForbidden()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/prohibido')
                ->assertJsonPath('status', 403);
        }

        $this->actAs('customer');
        $this->getJson(self::PREFIX.'/personal')->assertForbidden();
    }

    /** A route with no role, like a public one, is forbidden: it never lets anyone through by omission. */
    public function test_a_request_without_a_role_is_forbidden(): void
    {
        $this->getJson('/api/v1/pruebas/publico/'.self::BARBERSHOP_ID.'/dueno')->assertForbidden();
    }

    /** Act as the person who holds a role in the barbershop. */
    private function actAs(string $role): void
    {
        Sanctum::actingAs((new UserModel)->forceFill(['id' => self::USERS[$role]]), []);
    }
}
