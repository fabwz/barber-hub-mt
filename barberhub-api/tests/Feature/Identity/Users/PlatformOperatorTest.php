<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/**
 * The platform operator (RF-55, PL-01): an account marked by the artisan command, with a temporary password it must change, that logs in
 * like anyone, reaches the routes under `plataforma` and nothing else, and that no route of the API can create. Who is refused on those
 * routes, and what the operator gets on the routes of a barbershop, is in the permission matrix (AccessMatrixTest).
 */
final class PlatformOperatorTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string PLATFORM_ACCOUNT = '/api/v1/plataforma/cuenta';

    private const string NEW_PASSWORD = 'Nueva#Clave9';

    /** The command creates the account marked as operator, with a generated temporary password that it shows once and that is only stored hashed. */
    public function test_the_command_creates_the_operator_with_a_generated_temporary_password(): void
    {
        $exit = Artisan::call('platform:create-operator', ['email' => 'Operadora@Barberhub.test', 'name' => 'Operadora Plataforma']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);

        $user = UserModel::query()->where('email', 'operadora@barberhub.test')->sole();
        $this->assertTrue($user->is_platform_operator);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->phone);
        $this->assertSame(0, DB::table('memberships')->count());

        $password = $this->passwordShownIn($output);
        $this->assertSame([], PlainPassword::missingRequirements($password), 'The generated password breaks the policy.');
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
        $this->assertSame(1, substr_count($output, $password), 'The password must be shown exactly once.');
    }

    /** Every run generates another password. */
    public function test_each_generated_password_is_different(): void
    {
        Artisan::call('platform:create-operator', ['email' => 'uno@barberhub.test', 'name' => 'Uno']);
        $first = $this->passwordShownIn(Artisan::output());
        Artisan::call('platform:create-operator', ['email' => 'dos@barberhub.test', 'name' => 'Dos']);

        $this->assertNotSame($first, $this->passwordShownIn(Artisan::output()));
    }

    /** A password typed at the hidden prompt is used and never printed back. */
    public function test_a_typed_password_is_used_and_never_shown(): void
    {
        $this->typing('Escrita#Clave7')->doesntExpectOutputToContain('Escrita#Clave7')->assertSuccessful();

        $user = UserModel::query()->where('email', 'operadora@barberhub.test')->sole();
        $this->assertTrue(Hash::check('Escrita#Clave7', $user->password));
        $this->assertTrue($user->is_platform_operator);
    }

    /** A typed password that breaks the policy is refused and nothing is created. */
    public function test_a_weak_typed_password_is_refused(): void
    {
        $this->typing('debil')->assertFailed();

        $this->assertSame(0, UserModel::query()->count());
    }

    /** An email that already has an account is refused: an existing account is never turned into an operator. */
    public function test_an_existing_account_is_never_turned_into_an_operator(): void
    {
        UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => Hash::make('Clave#segura1')]);

        $exit = Artisan::call('platform:create-operator', ['email' => 'ana@barberhub.test', 'name' => 'Ana Mora']);

        $this->assertSame(1, $exit);
        $this->assertFalse(UserModel::query()->where('email', 'ana@barberhub.test')->sole()->is_platform_operator);
    }

    /** The operator logs in through the normal flow; with the temporary password they can only read their account and change it (RF-39, RF-40). */
    public function test_the_operator_logs_in_and_with_a_temporary_password_can_only_change_it(): void
    {
        Artisan::call('platform:create-operator', ['email' => 'operadora@barberhub.test', 'name' => 'Operadora Plataforma']);
        $temporary = $this->passwordShownIn(Artisan::output());
        $token = $this->login('operadora@barberhub.test', $temporary);

        $this->as($token)->getJson(self::PLATFORM_ACCOUNT)
            ->assertForbidden()
            ->assertJsonPath('type', 'http://localhost/problemas/contrasena-temporal');
        $this->as($token)->getJson('/api/v1/cuenta')->assertOk()->assertJsonPath('data.contrasena_temporal', true);

        $this->as($token)->putJson('/api/v1/cuenta/contrasena', ['contrasena_actual' => $temporary, 'contrasena_nueva' => self::NEW_PASSWORD])->assertNoContent();

        $id = (string) UserModel::query()->where('email', 'operadora@barberhub.test')->value('id');
        $this->as($token)->getJson(self::PLATFORM_ACCOUNT)
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $id, 'nombre' => 'Operadora Plataforma', 'correo' => 'operadora@barberhub.test']]);
    }

    /** The mark survives what the operator does to their own account: changing the password and correcting the profile keep it. */
    public function test_the_mark_survives_changes_to_the_own_account(): void
    {
        Artisan::call('platform:create-operator', ['email' => 'operadora@barberhub.test', 'name' => 'Operadora Plataforma']);
        $temporary = $this->passwordShownIn(Artisan::output());
        $token = $this->login('operadora@barberhub.test', $temporary);
        $this->as($token)->putJson('/api/v1/cuenta/contrasena', ['contrasena_actual' => $temporary, 'contrasena_nueva' => self::NEW_PASSWORD])->assertNoContent();

        $this->as($token)->putJson('/api/v1/cuenta', ['nombre' => 'Operadora Nueva', 'telefono' => '8888-0000', 'correo' => 'operadora@barberhub.test'])->assertOk();

        $this->assertTrue(UserModel::query()->where('email', 'operadora@barberhub.test')->sole()->is_platform_operator);
        $this->as($token)->getJson(self::PLATFORM_ACCOUNT)->assertOk()->assertJsonPath('data.nombre', 'Operadora Nueva');
    }

    /** A person with no session is refused with 401 on the platform route. */
    public function test_the_platform_route_asks_for_a_session(): void
    {
        $this->getJson(self::PLATFORM_ACCOUNT)->assertUnauthorized();
    }

    /** No route that writes an account accepts the mark: a customer who sends it for themselves or at registration stays a plain account (PL-01). */
    public function test_no_route_turns_an_account_into_an_operator(): void
    {
        $registered = $this->postJson('/api/v1/cuentas', [
            'nombre' => 'Ana Mora', 'correo' => 'ana@barberhub.test', 'telefono' => '8888-0000', 'contrasena' => 'Clave#segura1',
            'acepta_terminos' => true, 'acepta_privacidad' => true,
            'is_platform_operator' => true, 'operador_plataforma' => true, 'operador' => true,
        ]);
        $this->assertContains($registered->getStatusCode(), [Response::HTTP_CREATED, Response::HTTP_OK]);
        $this->assertFalse(UserModel::query()->where('email', 'ana@barberhub.test')->sole()->is_platform_operator);

        $token = $this->login('ana@barberhub.test', 'Clave#segura1');
        $this->as($token)->putJson('/api/v1/cuenta', [
            'nombre' => 'Ana Mora', 'telefono' => '8888-0000', 'correo' => 'ana@barberhub.test',
            'is_platform_operator' => true, 'operador_plataforma' => true,
        ])->assertOk();
        $this->as($token)->putJson('/api/v1/cuenta/avisos', ['recordatorios_correo' => true, 'is_platform_operator' => true]);

        $this->assertFalse(UserModel::query()->where('email', 'ana@barberhub.test')->sole()->is_platform_operator);
        $this->as($token)->getJson(self::PLATFORM_ACCOUNT)->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');
    }

    /** The only route of the platform that writes is the registration of a barbershop; it never takes the mark of the operator from the body (the command is the only way). */
    public function test_only_the_registration_of_a_barbershop_writes_and_it_cannot_create_an_operator(): void
    {
        $platformRoutes = array_filter(
            iterator_to_array(Route::getRoutes()->getRoutes()),
            fn (\Illuminate\Routing\Route $route): bool => str_starts_with($route->uri(), 'api/v1/plataforma'),
        );

        $this->assertNotEmpty($platformRoutes);

        foreach ($platformRoutes as $route) {
            $writes = $route->methods() !== ['GET', 'HEAD'];

            $this->assertSame($route->uri() === 'api/v1/plataforma/barberias', $writes, "{$route->uri()} has the wrong methods.");
        }

        $operator = UserModel::query()->create(['name' => 'Operadora', 'email' => 'operadora@barberhub.test', 'password' => Hash::make('Clave#Previa1'), 'is_platform_operator' => true]);
        $this->as($operator->createToken('Celular')->plainTextToken)->postJson('/api/v1/plataforma/barberias', [
            'nombre' => 'Pura Navaja',
            'ciudad' => 'San Carlos',
            'telefono' => '2460-0000',
            'dueno' => ['nombre' => 'Rodrigo Mora', 'correo' => 'rodrigo@barberhub.test', 'telefono' => '8888-0000', 'is_platform_operator' => true, 'operador_de_plataforma' => true],
            'is_platform_operator' => true,
        ])->assertCreated();

        $this->assertFalse(UserModel::query()->where('email', 'rodrigo@barberhub.test')->sole()->is_platform_operator);
    }

    /** `GET /cuenta` says whether the person is a platform operator, and says no for anyone else (RF-55). */
    public function test_the_own_account_says_whether_the_person_is_an_operator(): void
    {
        Artisan::call('platform:create-operator', ['email' => 'operadora@barberhub.test', 'name' => 'Operadora Plataforma']);
        $temporary = $this->passwordShownIn(Artisan::output());
        $token = $this->login('operadora@barberhub.test', $temporary);
        $this->as($token)->getJson('/api/v1/cuenta')->assertOk()->assertJsonPath('data.operador_de_plataforma', true)->assertJsonPath('data.contrasena_temporal', true);

        $customer = UserModel::query()->create(['name' => 'Cata', 'email' => 'cata@barberhub.test', 'password' => Hash::make('Clave#Previa1')]);
        $this->as($customer->createToken('Celular')->plainTextToken)->getJson('/api/v1/cuenta')->assertOk()->assertJsonPath('data.operador_de_plataforma', false);
    }

    /** Run the command as a person who types the temporary password at the hidden prompt. */
    private function typing(string $password): PendingCommand
    {
        $command = $this->artisan('platform:create-operator', ['email' => 'operadora@barberhub.test', 'name' => 'Operadora Plataforma']);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command->expectsQuestion('Temporary password (leave empty to generate one)', $password);
    }

    /**
     * The password the command printed: the line after the warning that says it is shown only once.
     */
    private function passwordShownIn(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line): bool => $line !== ''));
        $position = array_find_key($lines, fn (string $line): bool => str_contains($line, 'shown only now'));
        $this->assertNotNull($position, 'The command did not show a password.');

        return $lines[$position + 1];
    }

    /** Log in through the API and return the plain text token. */
    private function login(string $email, string $password): string
    {
        return $this->postJson('/api/v1/tokens', ['correo' => $email, 'contrasena' => $password, 'dispositivo' => 'Celular'])
            ->assertCreated()
            ->json('data.token');
    }

    /** Use a token for the next request, forgetting the user the guards kept from the previous one. */
    private function as(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }
}
