<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class RegisterBarbershopTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/barberias';

    private UserModel $user;

    private string $token;

    /** Create the account and the token every scenario registers with. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => 'hash']);
        $this->token = $this->user->createToken('Celular')->plainTextToken;
    }

    /** Without a token the registration answers the unauthenticated problem and stores nothing. */
    public function test_registration_requires_a_token(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/no-autenticado');

        $this->assertDatabaseCount('barbershops', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    /** Registering answers 201 and leaves the person as the active owner of the new barbershop (RF-01, UP-05). */
    public function test_barbershop_is_registered_and_the_person_becomes_its_owner(): void
    {
        $response = $this->register($this->payload());

        $id = DB::table('barbershops')->sole()->id;
        $response->assertCreated()->assertExactJson([
            'data' => [
                'id' => $id,
                'nombre' => 'Barbería Pura Navaja',
                'ciudad' => 'San Carlos',
                'telefono' => '2460-0000',
                'rol' => 'dueno',
            ],
        ]);

        $membership = DB::table('memberships')->sole();
        $this->assertSame($this->user->id, $membership->user_id);
        $this->assertSame($id, $membership->barbershop_id);
        $this->assertSame('owner', $membership->role);
        $this->assertTrue($membership->is_active);
    }

    /** One person can register several barbershops and owns each of them. */
    public function test_person_can_register_several_barbershops(): void
    {
        $this->register($this->payload())->assertCreated();
        $this->register($this->payload(['nombre' => 'Barbería La Navaja']))->assertCreated();

        $this->assertDatabaseCount('barbershops', 2);
        $this->assertSame(2, DB::table('memberships')->where('user_id', $this->user->id)->where('role', 'owner')->count());
    }

    /**
     * Invalid data is refused with an error for each wrong field and nothing is stored.
     *
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $fields
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_data_is_refused(array $changes, array $fields): void
    {
        $response = $this->register($this->payload($changes))
            ->assertUnprocessable()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/datos-invalidos');

        $this->assertSame($fields, array_keys($response->json('errores')));
        $this->assertDatabaseCount('barbershops', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    /**
     * Payload changes that break a rule, and the fields reported.
     *
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [['nombre' => null], ['nombre']],
            'name too long' => [['nombre' => str_repeat('a', 121)], ['nombre']],
            'city missing' => [['ciudad' => null], ['ciudad']],
            'city too long' => [['ciudad' => str_repeat('a', 81)], ['ciudad']],
            'phone missing' => [['telefono' => null], ['telefono']],
            'phone too long' => [['telefono' => str_repeat('1', 31)], ['telefono']],
            'name not text' => [['nombre' => ['x']], ['nombre']],
            'everything missing' => [['nombre' => null, 'ciudad' => null, 'telefono' => null], ['nombre', 'ciudad', 'telefono']],
        ];
    }

    /** A blank name that passes the shape check is still refused by the domain. */
    public function test_blank_name_is_refused(): void
    {
        $this->register($this->payload(['nombre' => '   ']))->assertUnprocessable();

        $this->assertDatabaseCount('barbershops', 0);
    }

    /** If the owner cannot be assigned, the barbershop is not created either. */
    public function test_failed_owner_assignment_leaves_no_barbershop_behind(): void
    {
        $this->app->instance(OwnerMemberships::class, new class implements OwnerMemberships
        {
            public function assignOwner(BarbershopId $barbershopId, string $userId): void
            {
                DB::table('barbershops')->sole();

                throw RoleAlreadyAssignedException::create();
            }
        });

        $this->register($this->payload())
            ->assertConflict()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/conflicto');

        $this->assertDatabaseCount('barbershops', 0);
        $this->assertDatabaseCount('memberships', 0);
    }

    /**
     * Build the payload of a barbershop, overriding only what a scenario changes.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(array $changes = []): array
    {
        return array_merge(['nombre' => 'Barbería Pura Navaja', 'ciudad' => 'San Carlos', 'telefono' => '2460-0000'], $changes);
    }

    /**
     * Register with the token of the person, forgetting the user the guards kept from the previous request.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function register(array $payload): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->token)->postJson(self::ENDPOINT, $payload);
    }
}
