<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Closures;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The owner marks holidays and special closures by date (RF-04, HO-01, UP-03). */
final class ManageClosuresTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $stranger;

    /** Two barbershops, an owner, a collaborator, a customer and a stranger; today is 20 October 2026 in Costa Rica. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Clock::class, new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-20 10:00:00', new DateTimeZone('America/Costa_Rica'));
            }
        });

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

    /** The owner closes a holiday and gets the closure back with its date as YYYY-MM-DD (RF-04). */
    public function test_owner_registers_a_closure(): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        $id = $response->json('data.id');
        $response->assertExactJson(['data' => ['id' => $id, 'fecha' => '2026-12-25', 'motivo' => 'Navidad']]);
        $this->assertTrue(Str::isUuid($id, 7));

        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('closures')->sole();
        $this->assertSame([$this->puraNavaja, '2026-12-25', 'Navidad'], [$row->barbershop_id, $row->closed_on, $row->reason]);
    }

    /** The reason is optional, and today can be closed. */
    public function test_reason_is_optional_and_today_is_accepted(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), ['fecha' => '2026-10-20'])
            ->assertCreated()
            ->assertJsonPath('data.fecha', '2026-10-20')
            ->assertJsonPath('data.motivo', null);
    }

    /** A date can be closed only once: the second closure answers 409 and the first one stays (HO-01). */
    public function test_a_date_cannot_be_closed_twice(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        $this->postJson($this->url($this->puraNavaja), $this->payload('Otra vez'))
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('conflicto'))
            ->assertJsonPath('detail', 'La barbería ya tiene un cierre en esa fecha.');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(['Navidad'], DB::table('closures')->pluck('reason')->all());
    }

    /** Another barbershop may close the same date. */
    public function test_another_barbershop_may_close_the_same_date(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();
        $this->createClosure($this->fadeLab, '2026-12-25');

        $this->assertSame(1, $this->countClosures($this->puraNavaja));
        $this->assertSame(1, $this->countClosures($this->fadeLab));
    }

    /**
     * Bodies the request refuses, with the field the error must name.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'without a date' => [[], 'fecha'],
            'date with slashes' => [['fecha' => '25/12/2026'], 'fecha'],
            'date that does not exist' => [['fecha' => '2026-02-30'], 'fecha'],
            'date with a time' => [['fecha' => '2026-12-25 10:00'], 'fecha'],
            'reason over 200 characters' => [['fecha' => '2026-12-25', 'motivo' => 'x'.str_repeat('a', 200)], 'motivo'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_closure_is_refused_naming_the_field(array $body, string $field): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $body)
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonValidationErrorFor($field, 'errores');

        $this->assertSame(0, $this->countClosures($this->puraNavaja));
    }

    /** A date that already passed is refused; the day is today in Costa Rica, not in UTC. */
    public function test_a_past_date_is_refused(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), ['fecha' => '2026-10-19'])
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonPath('detail', 'La fecha del cierre no puede ser anterior a hoy.');

        $this->assertSame(0, $this->countClosures($this->puraNavaja));
    }

    /** The owner lists the closures from today onward, ordered by date, and none of another barbershop. */
    public function test_owner_lists_the_closures_from_today(): void
    {
        Sanctum::actingAs($this->owner, []);
        $christmas = $this->createClosure($this->puraNavaja, '2026-12-25', 'Navidad');
        $today = $this->createClosure($this->puraNavaja, '2026-10-20');
        $this->createClosure($this->puraNavaja, '2026-10-19', 'Ya pasó');
        $this->createClosure($this->fadeLab, '2026-11-01');

        $this->getJson($this->url($this->puraNavaja))
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $today, 'fecha' => '2026-10-20', 'motivo' => null],
                ['id' => $christmas, 'fecha' => '2026-12-25', 'motivo' => 'Navidad'],
            ]]);
    }

    /** The owner deletes a closure and the date can be closed again. */
    public function test_owner_deletes_a_closure(): void
    {
        Sanctum::actingAs($this->owner, []);
        $id = $this->createClosure($this->puraNavaja, '2026-12-25');

        $this->deleteJson($this->url($this->puraNavaja, $id))->assertNoContent();

        $this->assertSame(0, $this->countClosures($this->puraNavaja));
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();
    }

    /** A closure that does not exist, and an identifier that is not one, are not found. */
    public function test_unknown_closure_is_not_found(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->deleteJson($this->url($this->puraNavaja, (string) Str::uuid7()))->assertNotFound()->assertJsonPath('type', $this->problem('no-encontrado'));
        $this->deleteJson($this->url($this->puraNavaja, 'no-es-un-uuid'))->assertNotFound();
    }

    /** A collaborator or a customer of the barbershop is refused with 403 "prohibido" in the three operations (UP-03). */
    public function test_a_member_who_is_not_the_owner_is_forbidden(): void
    {
        $id = $this->createClosure($this->puraNavaja, '2026-12-25');

        foreach ([$this->collaborator, $this->customer] as $member) {
            Sanctum::actingAs($member, []);

            $this->getJson($this->url($this->puraNavaja))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->postJson($this->url($this->puraNavaja), ['fecha' => '2026-12-26'])->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            $this->deleteJson($this->url($this->puraNavaja, $id))->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
        }

        $this->assertSame(1, $this->countClosures($this->puraNavaja));
    }

    /** A person without a role in the barbershop gets the 404 of a barbershop that does not exist, not a 403 (UP-06). */
    public function test_a_person_without_a_role_in_the_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->stranger, []);
        $id = $this->createClosure($this->puraNavaja, '2026-12-25');

        $expected = $this->getJson($this->url((string) Str::uuid7()))->assertNotFound()->json();
        unset($expected['instance']);

        foreach ([
            $this->getJson($this->url($this->puraNavaja)),
            $this->postJson($this->url($this->puraNavaja), ['fecha' => '2026-12-26']),
            $this->deleteJson($this->url($this->puraNavaja, $id)),
        ] as $response) {
            $body = $response->assertNotFound()->json();
            unset($body['instance']);
            $this->assertSame($expected, $body);
        }

        $this->assertSame(1, $this->countClosures($this->puraNavaja));
    }

    /** The owner of one barbershop cannot administer another one, nor a closure of another one (UP-06, MT-04). */
    public function test_the_owner_of_another_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->owner, []);
        $other = $this->createClosure($this->fadeLab, '2026-12-25');

        $this->getJson($this->url($this->fadeLab))->assertNotFound();
        $this->postJson($this->url($this->fadeLab), $this->payload())->assertNotFound();
        $this->deleteJson($this->url($this->fadeLab, $other))->assertNotFound();
        $this->deleteJson($this->url($this->puraNavaja, $other))->assertNotFound()->assertJsonPath('detail', 'Este cierre no existe');

        $this->assertSame(1, $this->countClosures($this->fadeLab));
    }

    /** The closures have no public route, and the administration ones pass through the session, the barbershop and the role of the owner, in that order. */
    public function test_routes_are_guarded_in_order_and_none_is_public(): void
    {
        foreach (['catalog.closures.index', 'catalog.closures.store', 'catalog.closures.destroy'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame(['auth:sanctum', 'barbershop', 'role:owner'], array_values(array_filter(
                $route->gatherMiddleware(),
                fn (mixed $middleware): bool => is_string($middleware) && in_array($middleware, ['auth:sanctum', 'barbershop', 'role:owner', 'barbershop:public'], true),
            )));
        }

        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/cierres")->assertNotFound();
    }

    /** Address of the closures the owner administers, or of one of them. */
    private function url(string $barbershopId, ?string $closureId = null): string
    {
        $url = "/api/v1/barberias/{$barbershopId}/administracion/cierres";

        return $closureId === null ? $url : "{$url}/{$closureId}";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /**
     * A valid body: Christmas, with a reason.
     *
     * @return array{fecha: string, motivo: string}
     */
    private function payload(string $reason = 'Navidad'): array
    {
        return ['fecha' => '2026-12-25', 'motivo' => $reason];
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

    /** Insert a closure of a barbershop straight into the table and return its identifier. */
    private function createClosure(string $barbershopId, string $date, ?string $reason = null): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('closures')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'closed_on' => $date, 'reason' => $reason]);

        return $id;
    }

    /** Number of closures a barbershop has, read as the barbershop. */
    private function countClosures(string $barbershopId): int
    {
        $this->scopeQueriesTo($barbershopId);

        return DB::table('closures')->count();
    }
}
