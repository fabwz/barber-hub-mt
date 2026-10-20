<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;
use Tests\Support\Shared\InMemoryBarbershopRoles;
use Tests\TestCase;

final class ScopeRequestToBarbershopTest extends TestCase
{
    private const string USER_ID = '0192f0c4-0000-7000-8000-0000000000a1';

    private const string BARBERSHOP_ID = '0192f0c4-0000-7000-8000-0000000000aa';

    private const string OTHER_ID = '0192f0c4-0000-7000-8000-0000000000bb';

    private const string PREFIX = '/api/v1/pruebas/barberias/';

    /** The person is an owner of the first barbershop and has no role in the second, which exists for others. */
    protected function setUp(): void
    {
        parent::setUp();

        $roles = new InMemoryBarbershopRoles;
        $roles->grant(self::USER_ID, self::BARBERSHOP_ID, 'owner');
        $this->app->instance(BarbershopRoles::class, $roles);

        Route::prefix('api/v1/pruebas')->middleware('api')->group(function (): void {
            Route::get('barberias/{barberia}/estado', fn (Request $request): array => self::state($request))
                ->middleware(['auth:sanctum', 'barbershop'])->whereUuid('barberia');
            Route::get('publico/{barberia}/estado', fn (Request $request): array => self::state($request))
                ->middleware('barbershop:public')->whereUuid('barberia');
            Route::get('modo/{barberia}/estado', fn (Request $request): array => self::state($request))
                ->middleware(['auth:sanctum', 'barbershop:inventado'])->whereUuid('barberia');
        });
    }

    /** A member's request runs in one transaction scoped to the barbershop of the URL, with the role they hold. */
    public function test_member_request_is_scoped_to_the_barbershop_of_the_url(): void
    {
        $this->actAsTheMember();

        $this->getJson(self::PREFIX.self::BARBERSHOP_ID.'/estado')->assertOk()->assertExactJson([
            'setting' => self::BARBERSHOP_ID,
            'context' => self::BARBERSHOP_ID,
            'role' => 'owner',
            'levels' => 1,
        ]);
    }

    /** A person without a role in the barbershop gets the not-found problem and the transaction never opens. */
    public function test_person_without_a_role_gets_not_found(): void
    {
        $this->actAsTheMember();

        $this->getJson(self::PREFIX.self::OTHER_ID.'/estado')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/no-encontrado');
    }

    /** Another person's barbershop and one that does not exist answer exactly like a missing resource (UP-06, MT-03). */
    public function test_not_member_and_unknown_barbershop_answer_like_a_missing_resource(): void
    {
        $this->actAsTheMember();

        $missing = $this->problem('/api/v1/pruebas/no-existe');
        $this->assertSame(404, $missing['status']);

        $this->assertSame($missing, $this->problem(self::PREFIX.self::OTHER_ID.'/estado'));
        $this->assertSame($missing, $this->problem(self::PREFIX.'0192f0c4-0000-7000-8000-0000000000ff/estado'));
        $this->assertSame($missing, $this->problem(self::PREFIX.'no-es-un-uuid/estado'));
    }

    /** Without a session the answer is 401, before anything about the barbershop is checked. */
    public function test_request_without_a_session_is_unauthenticated(): void
    {
        $this->getJson(self::PREFIX.self::BARBERSHOP_ID.'/estado')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'http://localhost/problemas/no-autenticado');
    }

    /** The barbershop is the one of the route: the body, the query and a header cannot change it. */
    public function test_body_query_and_header_do_not_choose_the_barbershop(): void
    {
        $this->actAsTheMember();

        $this->getJson(
            self::PREFIX.self::BARBERSHOP_ID.'/estado?barberia='.self::OTHER_ID.'&barbershop_id='.self::OTHER_ID,
            ['X-Barbershop' => self::OTHER_ID, 'X-Barbershop-Id' => self::OTHER_ID],
        )->assertOk()->assertJsonPath('setting', self::BARBERSHOP_ID)->assertJsonPath('context', self::BARBERSHOP_ID);
    }

    /** An identifier in capitals is the same barbershop, and the context always carries it in lowercase. */
    public function test_uppercase_identifier_is_normalized(): void
    {
        $this->actAsTheMember();

        $this->getJson(self::PREFIX.strtoupper(self::BARBERSHOP_ID).'/estado')
            ->assertOk()
            ->assertJsonPath('context', self::BARBERSHOP_ID);
    }

    /** A malformed identifier does not match any route, with or without a session. */
    public function test_malformed_identifier_is_not_found(): void
    {
        $this->getJson(self::PREFIX.'123/estado')->assertNotFound();

        $this->actAsTheMember();

        $this->getJson(self::PREFIX.'123/estado')->assertNotFound();
    }

    /** A public route sets the barbershop of the URL without a session and without checking any role. */
    public function test_public_mode_sets_the_barbershop_of_the_route_without_a_session(): void
    {
        $this->getJson('/api/v1/pruebas/publico/'.self::OTHER_ID.'/estado')->assertOk()->assertExactJson([
            'setting' => self::OTHER_ID,
            'context' => self::OTHER_ID,
            'role' => null,
            'levels' => 1,
        ]);
    }

    /** The barbershop leaves with the request: neither the attributes nor the setting of the connection survive. */
    public function test_context_does_not_survive_the_request(): void
    {
        $this->actAsTheMember();

        $this->getJson(self::PREFIX.self::BARBERSHOP_ID.'/estado')->assertOk();

        $request = $this->app->make('request');
        $this->assertFalse($request->attributes->has(RequestBarbershopContext::BARBERSHOP_ATTRIBUTE));
        $this->assertFalse($request->attributes->has(RequestBarbershopContext::ROLE_ATTRIBUTE));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('', DB::scalar("select current_setting('app.barbershop_id', true)"));
        $this->expectException(AuthenticationException::class);
        $this->app->make(BarbershopContext::class)->current();
    }

    /** The next request does not inherit the barbershop of the previous one. */
    public function test_consecutive_requests_do_not_share_the_barbershop(): void
    {
        $this->actAsTheMember();
        $this->getJson(self::PREFIX.self::BARBERSHOP_ID.'/estado')->assertOk();

        $this->getJson('/api/v1/pruebas/publico/'.self::OTHER_ID.'/estado')
            ->assertOk()
            ->assertJsonPath('setting', self::OTHER_ID);
    }

    /** A mode nobody defined is a mistake in the route and fails instead of letting the request through. */
    public function test_unknown_mode_fails(): void
    {
        $this->actAsTheMember();

        $this->getJson('/api/v1/pruebas/modo/'.self::BARBERSHOP_ID.'/estado')->assertStatus(500);
    }

    /**
     * What the request sees from inside the barbershop transaction.
     *
     * @return array{setting: mixed, context: string, role: mixed, levels: int}
     */
    private static function state(Request $request): array
    {
        return [
            'setting' => DB::scalar("select current_setting('app.barbershop_id', true)"),
            'context' => app(BarbershopContext::class)->current()->value,
            'role' => $request->attributes->get(RequestBarbershopContext::ROLE_ATTRIBUTE),
            'levels' => DB::transactionLevel(),
        ];
    }

    /** Authenticate the person with an account built in memory, without touching the database. */
    private function actAsTheMember(): void
    {
        Sanctum::actingAs((new UserModel)->forceFill(['id' => self::USER_ID]), []);
    }

    /**
     * Problem document of a request without its per-request identifier.
     *
     * @return array<string, mixed>
     */
    private function problem(string $uri): array
    {
        $body = $this->getJson($uri)->assertNotFound()->json();
        unset($body['instance']);

        return $body;
    }
}
