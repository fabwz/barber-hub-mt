<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** A person changes their password with the current one, and a temporary password must be changed before anything else (RF-40, UP-04, SE-01, SE-07). */
final class ChangePasswordTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/cuenta/contrasena';

    private const string TEMPORARY = 'Temporal#2026';

    private const string CURRENT = 'Clave#segura1';

    private const string NEW = 'Nueva#Clave9';

    private const string BARBERSHOP = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    /** A wrong current password is refused with 422 on its own field and nothing changes. */
    public function test_a_wrong_current_password_is_rejected(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $token = $this->login('ana@barberhub.test', self::CURRENT);

        $response = $this->change($token, 'Otra#Clave1', self::NEW);

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonPath('errores.contrasena_actual.0', 'La contraseña actual no es correcta.');
        $this->assertPasswordIs('ana@barberhub.test', self::CURRENT);
    }

    /** A new password that misses requirements is refused with every missing one listed. */
    public function test_a_new_password_that_breaks_the_policy_is_rejected(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $token = $this->login('ana@barberhub.test', self::CURRENT);

        $response = $this->change($token, self::CURRENT, 'corta');

        $response->assertUnprocessable()
            ->assertJsonCount(4, 'errores.contrasena_nueva')
            ->assertJsonMissing(['corta']);
        $this->assertPasswordIs('ana@barberhub.test', self::CURRENT);
    }

    /** Both fields are required. */
    public function test_both_passwords_are_required(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $token = $this->login('ana@barberhub.test', self::CURRENT);

        Auth::forgetGuards();
        $this->withToken($token)->putJson(self::ENDPOINT, [])
            ->assertUnprocessable()
            ->assertJsonStructure(['errores' => ['contrasena_actual', 'contrasena_nueva']]);
    }

    /** The change works: the new password logs in, the old one no longer does, the hash is stored and nothing is echoed. */
    public function test_the_password_is_changed(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $token = $this->login('ana@barberhub.test', self::CURRENT);

        $response = $this->change($token, self::CURRENT, self::NEW);

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
        $this->assertPasswordIs('ana@barberhub.test', self::NEW);
        $this->postJson('/api/v1/tokens', ['correo' => 'ana@barberhub.test', 'contrasena' => self::CURRENT, 'dispositivo' => 'Celular'])->assertUnauthorized();
        $this->postJson('/api/v1/tokens', ['correo' => 'ana@barberhub.test', 'contrasena' => self::NEW, 'dispositivo' => 'Celular'])->assertCreated();
        $this->assertSame(0, DB::table('users')->where('password', self::NEW)->count());
    }

    /** A new password equal to the current one is refused on its field and the password does not change. */
    public function test_a_new_password_equal_to_the_current_one_is_rejected(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $token = $this->login('ana@barberhub.test', self::CURRENT);

        $this->change($token, self::CURRENT, self::CURRENT)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonPath('errores.contrasena_nueva.0', 'Elija una contraseña distinta de la actual.')
            ->assertJsonMissingPath('errores.contrasena_actual');

        $this->assertPasswordIs('ana@barberhub.test', self::CURRENT);
        $this->assertSame(['Celular'], $this->tokenNames());
    }

    /** Repeating the temporary password does not release the lock: the account still answers 403 elsewhere. */
    public function test_repeating_the_temporary_password_keeps_the_lock(): void
    {
        $this->account('kevin@barberhub.test', self::TEMPORARY, temporary: true);
        $token = $this->login('kevin@barberhub.test', self::TEMPORARY);

        $this->change($token, self::TEMPORARY, self::TEMPORARY)
            ->assertUnprocessable()
            ->assertJsonPath('errores.contrasena_nueva.0', 'Elija una contraseña distinta de la actual.');

        $this->assertDatabaseHas('users', ['email' => 'kevin@barberhub.test', 'must_change_password' => true]);
        $this->assertPasswordIs('kevin@barberhub.test', self::TEMPORARY);
        $this->mine($token)->assertForbidden()
            ->assertJsonPath('type', 'http://localhost/problemas/contrasena-temporal');
    }

    /** A different password changes it and releases the lock. */
    public function test_a_different_password_releases_the_lock(): void
    {
        $this->account('kevin@barberhub.test', self::TEMPORARY, temporary: true);
        $token = $this->login('kevin@barberhub.test', self::TEMPORARY);

        $this->change($token, self::TEMPORARY, self::NEW)->assertNoContent();

        $this->assertDatabaseHas('users', ['email' => 'kevin@barberhub.test', 'must_change_password' => false]);
        $this->assertPasswordIs('kevin@barberhub.test', self::NEW);
        $this->mine($token)->assertOk();
    }

    /** A wrong current password keeps its own error even if the new one repeats it. */
    public function test_a_wrong_current_password_keeps_its_error_when_the_new_one_repeats_it(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $token = $this->login('ana@barberhub.test', self::CURRENT);

        $this->change($token, 'Otra#Clave1', 'Otra#Clave1')
            ->assertUnprocessable()
            ->assertJsonPath('errores.contrasena_actual.0', 'La contraseña actual no es correcta.')
            ->assertJsonMissingPath('errores.contrasena_nueva');
    }

    /** The other sessions close and the one that changed the password stays open. */
    public function test_the_other_sessions_are_closed_and_the_current_one_stays(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $this->account('luis@barberhub.test', self::CURRENT);
        $phone = $this->login('ana@barberhub.test', self::CURRENT, 'Celular');
        $laptop = $this->login('ana@barberhub.test', self::CURRENT, 'Computadora');
        $this->login('luis@barberhub.test', self::CURRENT, 'Tablet del local');

        $this->change($phone, self::CURRENT, self::NEW)->assertNoContent();

        $this->assertSame(['Celular', 'Tablet del local'], $this->tokenNames());
        $this->mine($phone)->assertOk();
        $this->mine($laptop)->assertUnauthorized();
    }

    /** Without a session the route answers 401. */
    public function test_without_a_session_it_answers_unauthenticated(): void
    {
        $this->putJson(self::ENDPOINT, ['contrasena_actual' => self::CURRENT, 'contrasena_nueva' => self::NEW])
            ->assertUnauthorized()
            ->assertJsonPath('type', 'http://localhost/problemas/no-autenticado');
    }

    /** A collaborator with the temporary password is blocked on every route that asks for a session, with a stable type. */
    public function test_a_temporary_password_blocks_the_other_routes(): void
    {
        $this->account('kevin@barberhub.test', self::TEMPORARY, temporary: true);
        $token = $this->login('kevin@barberhub.test', self::TEMPORARY);

        foreach ([
            fn (): TestResponse => $this->mine($token),
            fn (): TestResponse => $this->withToken($token)->getJson('/api/v1/barberias/'.self::BARBERSHOP.'/mis-reservas'),
            fn (): TestResponse => $this->withToken($token)->postJson('/api/v1/barberias', []),
        ] as $call) {
            Auth::forgetGuards();

            $call()->assertForbidden()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'http://localhost/problemas/contrasena-temporal')
                ->assertJsonPath('status', 403)
                ->assertJsonPath('detail', 'Debe cambiar su contraseña temporal antes de usar el resto de la aplicación.');
        }
    }

    /** The routes of the exemption still answer: the change of password and both closings of the session. */
    public function test_a_temporary_password_still_reaches_the_change_and_the_logout(): void
    {
        $this->account('kevin@barberhub.test', self::TEMPORARY, temporary: true);
        $phone = $this->login('kevin@barberhub.test', self::TEMPORARY, 'Celular');
        $laptop = $this->login('kevin@barberhub.test', self::TEMPORARY, 'Computadora');
        $tablet = $this->login('kevin@barberhub.test', self::TEMPORARY, 'Tablet');

        $this->change($phone, 'Otra#Clave1', self::NEW)->assertUnprocessable();
        Auth::forgetGuards();
        $this->withToken($laptop)->deleteJson('/api/v1/tokens/actual')->assertNoContent();
        Auth::forgetGuards();
        $this->withToken($tablet)->deleteJson('/api/v1/tokens')->assertNoContent();
    }

    /** Changing the temporary password releases the account at once, with the same token. */
    public function test_changing_the_temporary_password_releases_the_account(): void
    {
        $this->account('kevin@barberhub.test', self::TEMPORARY, temporary: true);
        $token = $this->login('kevin@barberhub.test', self::TEMPORARY);
        $this->mine($token)->assertForbidden();

        $this->change($token, self::TEMPORARY, self::NEW)->assertNoContent();

        $this->assertDatabaseHas('users', ['email' => 'kevin@barberhub.test', 'must_change_password' => false]);
        $this->mine($token)->assertOk();
    }

    /** An owner or a customer, whose accounts carry no temporary password, are not affected, and the public routes need no session. */
    public function test_accounts_without_the_flag_and_public_routes_are_not_affected(): void
    {
        $this->account('ana@barberhub.test', self::CURRENT);
        $token = $this->login('ana@barberhub.test', self::CURRENT);

        $this->mine($token)->assertOk();
        $this->getJson('/api/v1')->assertOk();
        $this->getJson('/api/v1/directorio')->assertOk();
    }

    /** A temporary password does not block a public route even when the request carries its token. */
    public function test_a_temporary_password_does_not_block_a_public_route(): void
    {
        $this->account('kevin@barberhub.test', self::TEMPORARY, temporary: true);
        $token = $this->login('kevin@barberhub.test', self::TEMPORARY);

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/directorio')->assertOk();
    }

    /** An invalid token on a route that asks for a session still answers 401, not 403. */
    public function test_an_invalid_token_answers_unauthenticated(): void
    {
        $this->withToken('1|invalido')->getJson('/api/v1/mis-barberias')->assertUnauthorized();
    }

    /** Create an active account whose password is the one given. */
    private function account(string $email, string $password, bool $temporary = false): void
    {
        UserModel::query()->create([
            'name' => 'Ana Mora',
            'email' => $email,
            'password' => Hash::make($password),
            'must_change_password' => $temporary,
        ]);
    }

    /** Log in through the API and return the plain text token. */
    private function login(string $email, string $password, string $device = 'Celular'): string
    {
        return $this->postJson('/api/v1/tokens', ['correo' => $email, 'contrasena' => $password, 'dispositivo' => $device])
            ->assertCreated()
            ->json('data.token');
    }

    /**
     * Ask to change the password with a token, forgetting the user the guards kept from the previous request.
     *
     * @return TestResponse<Response>
     */
    private function change(string $token, string $current, string $new): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->putJson(self::ENDPOINT, ['contrasena_actual' => $current, 'contrasena_nueva' => $new]);
    }

    /**
     * Read the barbershops of the person with a token, a route that only asks for a session.
     *
     * @return TestResponse<Response>
     */
    private function mine(string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/mis-barberias');
    }

    /** Check the stored hash of the account matches the password and is not the password itself. */
    private function assertPasswordIs(string $email, string $password): void
    {
        $hash = (string) DB::table('users')->where('email', $email)->value('password');

        $this->assertNotSame($password, $hash);
        $this->assertTrue(Hash::check($password, $hash));
    }

    /**
     * Device names of the tokens still stored.
     *
     * @return list<mixed>
     */
    private function tokenNames(): array
    {
        return array_values(DB::table('personal_access_tokens')->orderBy('name')->pluck('name')->all());
    }
}
