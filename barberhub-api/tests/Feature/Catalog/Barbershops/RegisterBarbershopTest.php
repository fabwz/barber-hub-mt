<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Barbershops;

use DateTimeImmutable;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Catalog\Barbershops\Application\Contracts\RentInstallments;
use Src\Catalog\Barbershops\Application\UseCases\RegisterBarbershop;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\OperatorCannotHoldRoleException;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Identity\Memberships\Infrastructure\Persistence\Repositories\EloquentMembershipRepository;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;
use Throwable;

/**
 * The registration of a barbershop and its owner by the platform operator (RF-01, PL-02): who may do it, what it creates in one transaction,
 * what happens with the account of the owner, and that nothing stays when something fails.
 */
final class RegisterBarbershopTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string ENDPOINT = '/api/v1/plataforma/barberias';

    private const string PASSWORD = 'Clave#Previa1';

    private UserModel $operator;

    private string $token;

    /** @var list<string> */
    private array $logged = [];

    /** Create the account of the operator, with its password already changed, and listen to everything the application logs. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = UserModel::query()->create(['name' => 'Operadora', 'email' => 'operadora@barberhub.test', 'password' => Hash::make(self::PASSWORD), 'is_platform_operator' => true]);
        $this->token = $this->operator->createToken('Celular')->plainTextToken;

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);

            $exception = $event->context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                $this->logged[] = (string) $exception;
            }
        });
    }

    /** Without a token the registration answers the unauthenticated problem and stores nothing. */
    public function test_registration_requires_a_token(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/no-autenticado');

        $this->assertNothingWasRegistered();
    }

    /** An owner, a collaborator, a customer and an account with no role are all refused with 403 `prohibido`, and nothing is stored (PL-02). */
    public function test_only_the_operator_may_register_a_barbershop(): void
    {
        $existing = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $existing, 'name' => 'Fade Lab', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'fade-lab']);
        $before = [DB::table('barbershops')->count(), DB::table('users')->count()];

        foreach (['owner', 'collaborator', 'customer', null] as $role) {
            $user = UserModel::query()->create(['name' => "Persona {$role}", 'email' => "{$role}@barberhub.test", 'password' => Hash::make(self::PASSWORD)]);

            if ($role !== null) {
                DB::table('memberships')->insert(['user_id' => $user->id, 'barbershop_id' => $existing, 'role' => $role, 'is_active' => true]);
            }

            Auth::forgetGuards();
            $this->withToken($user->createToken('Celular')->plainTextToken)->postJson(self::ENDPOINT, $this->payload())
                ->assertForbidden()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/prohibido');
        }

        $this->assertSame($before[0], DB::table('barbershops')->count());
        $this->assertSame($before[1] + 4, DB::table('users')->count());
        $this->assertSame(3, DB::table('memberships')->count());
    }

    /** The route that was open to any account no longer exists: not even the operator registers through it. */
    public function test_the_old_open_route_is_gone(): void
    {
        $status = $this->withToken($this->token)->postJson('/api/v1/barberias', ['nombre' => 'Pura Navaja', 'ciudad' => 'San Carlos', 'telefono' => '2460-0000'])->getStatusCode();

        $this->assertContains($status, [Response::HTTP_NOT_FOUND, Response::HTTP_METHOD_NOT_ALLOWED]);
        $this->assertNothingWasRegistered();
    }

    /**
     * A new email: the barbershop is born without services and hours, with no payment, its link and its first rent installment; the account
     * of the owner is created with a temporary password that comes back once, and the owner gets the role (RF-01, UP-04, UP-05).
     */
    public function test_a_new_email_registers_the_barbershop_and_creates_the_owner_account(): void
    {
        $response = $this->register($this->payload());

        $barbershop = DB::table('barbershops')->sole();
        $account = UserModel::query()->where('email', 'rodrigo@barberhub.test')->sole();
        $password = $response->json('data.dueno.contrasena_temporal');

        $response->assertCreated()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['data' => [
                'id' => $barbershop->id,
                'nombre' => 'Barbería Pura Navaja',
                'ciudad' => 'San Carlos',
                'telefono' => '2460-0000',
                'enlace' => 'pura-navaja',
                'dueno' => ['correo' => 'rodrigo@barberhub.test', 'cuenta_creada' => true, 'contrasena_temporal' => $password],
            ]]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // The barbershop: no services, no hours, no payment, with its link.
        $this->assertSame('pura-navaja', $barbershop->public_link);
        $this->assertSame('none', $barbershop->payment_mode);
        $this->assertNull($barbershop->payment_gateway);
        $this->scopeQueriesTo($barbershop->id);
        $this->assertSame(0, DB::table('services')->count());
        $this->assertSame(0, DB::table('opening_hours')->count());

        // The account: active, with the data given, a temporary password that follows the policy and is stored only hashed.
        $this->assertSame('Rodrigo Mora', $account->name);
        $this->assertSame('8888-0000', $account->phone);
        $this->assertTrue($account->is_active);
        $this->assertTrue($account->must_change_password);
        $this->assertFalse($account->is_platform_operator);
        $this->assertIsString($password);
        $this->assertSame([], PlainPassword::missingRequirements($password));
        $this->assertTrue(Hash::check($password, $account->password));
        $this->assertNotSame($password, $account->password);

        // The role.
        $membership = DB::table('memberships')->sole();
        $this->assertSame($account->id, $membership->user_id);
        $this->assertSame($barbershop->id, $membership->barbershop_id);
        $this->assertSame('owner', $membership->role);
        $this->assertTrue($membership->is_active);

        // The first installment of the rent.
        $installment = DB::table('rent_installments')->sole();
        $this->assertSame('pending', $installment->status);
        $this->assertSame($barbershop->id, $installment->barbershop_id);
    }

    /** The temporary password is in the response and nowhere else: not in the technical record, not in the logs, and a second reading of the account does not show it. */
    public function test_the_temporary_password_is_shown_once_and_written_nowhere(): void
    {
        $password = (string) $this->register($this->payload())->assertCreated()->json('data.dueno.contrasena_temporal');
        $barbershop = DB::table('barbershops')->sole()->id;
        $this->scopeQueriesTo($barbershop);

        $this->assertStringNotContainsString($password, (string) json_encode(DB::table('audit_log')->get()));
        $this->assertStringNotContainsString($password, (string) json_encode(DB::table('users')->get()));

        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString($password, $line);
        }

        // The owner enters with it, is told to change it, and the account never shows it again.
        Auth::forgetGuards();
        $token = (string) $this->postJson('/api/v1/tokens', ['correo' => 'rodrigo@barberhub.test', 'contrasena' => $password, 'dispositivo' => 'Celular'])->assertCreated()->json('data.token');
        Auth::forgetGuards();
        $account = $this->withToken($token)->getJson('/api/v1/cuenta')->assertOk()->assertJsonPath('data.contrasena_temporal', true)->assertJsonPath('data.operador_de_plataforma', false);
        $this->assertStringNotContainsString($password, (string) $account->getContent());
    }

    /** Each registration generates another password. */
    public function test_each_account_gets_a_different_password(): void
    {
        $first = $this->register($this->payload())->json('data.dueno.contrasena_temporal');
        $second = $this->register($this->payload(['nombre' => 'Barbería La Navaja'], ['correo' => 'luisa@barberhub.test']))->json('data.dueno.contrasena_temporal');

        $this->assertIsString($first);
        $this->assertIsString($second);
        $this->assertNotSame($first, $second);
    }

    /** An email that already has an account: the person becomes the owner, and the password, the name, the phone and the temporary mark stay as they were (PL-02, UP-05). */
    public function test_an_existing_email_gets_the_role_without_changing_the_account(): void
    {
        $existing = UserModel::query()->create(['name' => 'Daniela Solís', 'email' => 'rodrigo@barberhub.test', 'phone' => '7000-1111', 'password' => Hash::make(self::PASSWORD)]);
        $usersBefore = DB::table('users')->count();
        $row = (array) DB::table('users')->where('id', $existing->id)->first();

        $response = $this->register($this->payload(dueno: ['correo' => 'RODRIGO@barberhub.test', 'nombre' => 'Otro Nombre', 'telefono' => '8888-0000']));

        $barbershop = DB::table('barbershops')->sole();
        $response->assertCreated()->assertExactJson(['data' => [
            'id' => $barbershop->id,
            'nombre' => 'Barbería Pura Navaja',
            'ciudad' => 'San Carlos',
            'telefono' => '2460-0000',
            'enlace' => 'pura-navaja',
            'dueno' => ['correo' => 'rodrigo@barberhub.test', 'cuenta_creada' => false, 'contrasena_temporal' => null],
        ]]);
        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->assertSame($row, (array) DB::table('users')->where('id', $existing->id)->first(), 'The account of the owner changed.');
        $this->assertTrue(Hash::check(self::PASSWORD, (string) UserModel::query()->findOrFail($existing->id)->password));

        $membership = DB::table('memberships')->sole();
        $this->assertSame($existing->id, $membership->user_id);
        $this->assertSame('owner', $membership->role);
    }

    /** The second and third barbershop with the same name get the next number, whatever the accents and capitals (RF-02, MT-07). */
    public function test_homonyms_get_consecutive_links(): void
    {
        $links = [
            $this->register($this->payload())->assertCreated()->json('data.enlace'),
            $this->register($this->payload(['nombre' => 'Barbería Pura Navaja']))->assertCreated()->json('data.enlace'),
            $this->register($this->payload(['nombre' => 'BARBERIA PURA NAVAJA']))->assertCreated()->json('data.enlace'),
        ];

        $this->assertSame(['pura-navaja', 'pura-navaja-2', 'pura-navaja-3'], $links);
        $this->assertSame($links, DB::table('barbershops')->orderBy('created_at')->orderBy('id')->pluck('public_link')->all());
    }

    /** The name is reduced to lowercase words without accents, symbols or repeated spaces (RF-02). */
    public function test_link_drops_accents_symbols_and_repeated_spaces(): void
    {
        $this->register($this->payload(['nombre' => '  Peluquería   Ñandú -- Éxito & Co.  ']))
            ->assertCreated()
            ->assertJsonPath('data.enlace', 'peluqueria-nandu-exito-co');
    }

    /** The registration is in the technical record with the operator as author, the barbershop as subject and no sensitive data (SE-04). */
    public function test_the_registration_is_recorded_with_the_operator_as_author(): void
    {
        $response = $this->register($this->payload())->assertCreated();
        $barbershop = (string) $response->json('data.id');
        $owner = UserModel::query()->where('email', 'rodrigo@barberhub.test')->sole();

        $this->scopeQueriesTo($barbershop);
        $entry = DB::table('audit_log')->sole();

        $this->assertSame($this->operator->id, $entry->actor_user_id);
        $this->assertSame($barbershop, $entry->barbershop_id);
        $this->assertSame('barbershop.registered', $entry->action);
        $this->assertSame('barbershop', $entry->subject_type);
        $this->assertSame($barbershop, $entry->subject_id);
        $this->assertEquals(
            ['public_link' => ['previous' => null, 'new' => 'pura-navaja'], 'owner_user_id' => ['previous' => null, 'new' => $owner->id], 'owner_account_created' => ['previous' => null, 'new' => true]],
            json_decode((string) $entry->changes, true, flags: JSON_THROW_ON_ERROR),
        );

        foreach (['rodrigo@barberhub.test', '8888-0000', '2460-0000', (string) $response->json('data.dueno.contrasena_temporal')] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, (string) json_encode($entry));
        }
    }

    /** An email that is an operator is refused with 409 `conflicto`: no barbershop, no account change, no role, no installment and no entry stay (PL-01). */
    public function test_an_operator_cannot_be_the_owner_of_a_barbershop(): void
    {
        $other = UserModel::query()->create(['name' => 'Otra Operadora', 'email' => 'otra@barberhub.test', 'password' => Hash::make(self::PASSWORD), 'is_platform_operator' => true]);

        $this->register($this->payload(dueno: ['correo' => 'otra@barberhub.test']))
            ->assertConflict()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/conflicto')
            ->assertJsonPath('detail', 'Una cuenta de operador de plataforma no puede tener un rol en una barbería.');

        $this->assertNothingWasRegistered();
        $this->assertSame(2, DB::table('users')->count());
        $this->assertTrue(UserModel::query()->findOrFail($other->id)->is_platform_operator);
    }

    /** The operator that registers cannot be the owner of what they register either. */
    public function test_the_operator_cannot_name_themselves_as_the_owner(): void
    {
        $this->register($this->payload(dueno: ['correo' => 'operadora@barberhub.test']))->assertConflict();

        $this->assertNothingWasRegistered();
    }

    /** The database refuses a role for an operator on its own, whichever path tries it, and the repository answers it as the same refusal (PL-01). */
    public function test_the_database_refuses_a_role_for_an_operator(): void
    {
        $barbershop = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $barbershop, 'name' => 'Fade Lab', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'fade-lab']);

        try {
            DB::transaction(fn (): bool => DB::table('memberships')->insert(['user_id' => $this->operator->id, 'barbershop_id' => $barbershop, 'role' => 'customer', 'is_active' => true]));
            $this->fail('The database accepted a role for an operator.');
        } catch (Throwable $exception) {
            $this->assertSame('23514', (string) $exception->getCode());
            $this->assertStringContainsString('platform operator', $exception->getMessage());
        }

        $repository = new EloquentMembershipRepository;

        try {
            $repository->save(Membership::create($repository->nextId(), new UserId($this->operator->id), new BarbershopId($barbershop), Role::Customer));
            $this->fail('The repository accepted a role for an operator.');
        } catch (OperatorCannotHoldRoleException) {
            $this->assertSame(0, DB::table('memberships')->count());
        }
    }

    /** When the owner cannot be assigned, neither the barbershop, nor the new account, nor the installment, nor the entry stay (RF-01). */
    public function test_a_failure_after_the_account_leaves_nothing_behind(): void
    {
        $this->app->instance(OwnerMemberships::class, new class implements OwnerMemberships
        {
            public function assignOwner(BarbershopId $barbershopId, string $userId): void
            {
                DB::table('barbershops')->sole();
                DB::table('users')->where('id', $userId)->sole();

                throw RoleAlreadyAssignedException::create();
            }
        });

        $this->register($this->payload())
            ->assertConflict()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/conflicto');

        $this->assertNothingWasRegistered();
        $this->assertSame(1, DB::table('users')->count());
    }

    /** When the first installment cannot be created, the barbershop, the new account and the role do not stay either. */
    public function test_a_failure_of_the_rent_leaves_nothing_behind(): void
    {
        $this->app->instance(RentInstallments::class, new class implements RentInstallments
        {
            public function openFor(BarbershopId $barbershopId, DateTimeImmutable $now): void
            {
                DB::table('memberships')->sole();

                throw new \RuntimeException('The rent could not be opened.');
            }
        });

        $this->register($this->payload())->assertInternalServerError();

        $this->assertNothingWasRegistered();
        $this->assertSame(1, DB::table('users')->count());
    }

    /** When every allowed link is taken the registration answers a conflict and stores neither barbershop, nor account, nor role. */
    public function test_exhausted_links_answer_a_conflict_and_store_nothing(): void
    {
        $links = ['pura-navaja', ...array_map(fn (int $number): string => "pura-navaja-{$number}", range(2, RegisterBarbershop::MAX_LINK_ATTEMPTS))];
        DB::table('barbershops')->insert(array_map(
            fn (string $link): array => ['name' => 'Otra', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link],
            $links,
        ));

        $this->register($this->payload())
            ->assertConflict()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/conflicto')
            ->assertJsonPath('detail', 'No se pudo asignar un enlace libre a la barbería. Intente con otro nombre.');

        $this->assertDatabaseCount('barbershops', RegisterBarbershop::MAX_LINK_ATTEMPTS);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertSame(1, DB::table('users')->count());
    }

    /**
     * Invalid data is refused with an error for each wrong field and nothing is stored.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $owner
     * @param  list<string>  $fields
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_data_is_refused(array $changes, array $owner, array $fields): void
    {
        $response = $this->register($this->payload($changes, $owner))
            ->assertUnprocessable()
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/datos-invalidos');

        $this->assertSame($fields, array_keys($response->json('errores')));
        $this->assertNothingWasRegistered();
        $this->assertSame(1, DB::table('users')->count());
    }

    /**
     * Payload changes that break a rule, and the fields reported.
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>, list<string>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name missing' => [['nombre' => null], [], ['nombre']],
            'name too long' => [['nombre' => str_repeat('a', 121)], [], ['nombre']],
            'city missing' => [['ciudad' => null], [], ['ciudad']],
            'city too long' => [['ciudad' => str_repeat('a', 81)], [], ['ciudad']],
            'phone missing' => [['telefono' => null], [], ['telefono']],
            'phone too long' => [['telefono' => str_repeat('1', 31)], [], ['telefono']],
            'name not text' => [['nombre' => ['x']], [], ['nombre']],
            'owner missing' => [['dueno' => null], [], ['dueno', 'dueno.nombre', 'dueno.correo', 'dueno.telefono']],
            'owner name missing' => [[], ['nombre' => null], ['dueno.nombre']],
            'owner email malformed' => [[], ['correo' => 'rodrigo.barberhub'], ['dueno.correo']],
            'owner email missing' => [[], ['correo' => null], ['dueno.correo']],
            'owner phone missing' => [[], ['telefono' => null], ['dueno.telefono']],
            'everything missing' => [['nombre' => null, 'ciudad' => null, 'telefono' => null, 'dueno' => null], [], ['nombre', 'ciudad', 'telefono', 'dueno', 'dueno.nombre', 'dueno.correo', 'dueno.telefono']],
        ];
    }

    /** A blank name that passes the shape check is still refused by the domain, and so is a blank name of the owner. */
    public function test_blank_names_are_refused_by_the_domain(): void
    {
        $this->register($this->payload(['nombre' => '   ']))->assertUnprocessable();
        $this->register($this->payload(dueno: ['nombre' => '   ']))->assertUnprocessable();

        $this->assertNothingWasRegistered();
        $this->assertSame(1, DB::table('users')->count());
    }

    /**
     * Build the payload of a barbershop and its owner, overriding only what a scenario changes.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $dueno
     * @return array<string, mixed>
     */
    private function payload(array $changes = [], array $dueno = []): array
    {
        return array_merge([
            'nombre' => 'Barbería Pura Navaja',
            'ciudad' => 'San Carlos',
            'telefono' => '2460-0000',
            'dueno' => array_merge(['nombre' => 'Rodrigo Mora', 'correo' => 'rodrigo@barberhub.test', 'telefono' => '8888-0000'], $dueno),
        ], $changes);
    }

    /**
     * Register with the token of the operator, forgetting the user the guards kept from the previous request.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function register(array $payload): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($this->token)->postJson(self::ENDPOINT, $payload);
    }

    /** No barbershop and no role exist; the installments and the entries of the record hang from a barbershop, so none can be left either. */
    private function assertNothingWasRegistered(): void
    {
        $this->assertSame(0, DB::table('barbershops')->count());
        $this->assertSame(0, DB::table('memberships')->count());
    }
}
