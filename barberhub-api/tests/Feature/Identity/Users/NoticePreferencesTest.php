<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** A person turns the reminders by email of their own account on or off, and the choice stays (RF-47, NO-10). */
final class NoticePreferencesTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/cuenta/avisos';

    private const string PASSWORD = 'Clave#segura1';

    /** A new account wants the reminders until it says otherwise, and the answer carries only that. */
    public function test_the_reminders_are_on_by_default(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test');
        $token = $this->login('ana@barberhub.test');

        $this->show($token)->assertOk()->assertExactJson(['data' => ['recordatorios_correo' => true]]);
    }

    /** Turning them off and on again is stored in the account and answered with the same resource. */
    public function test_the_person_turns_the_reminders_off_and_on(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test');
        $token = $this->login('ana@barberhub.test');

        $this->update($token, ['recordatorios_correo' => false])
            ->assertOk()
            ->assertExactJson(['data' => ['recordatorios_correo' => false]]);
        $this->assertFalse(UserModel::query()->sole()->reminders_enabled);
        $this->show($token)->assertOk()->assertJsonPath('data.recordatorios_correo', false);

        $this->update($token, ['recordatorios_correo' => true])
            ->assertOk()
            ->assertExactJson(['data' => ['recordatorios_correo' => true]]);
        $this->assertTrue(UserModel::query()->sole()->reminders_enabled);
    }

    /** The choice lives in the account, so it survives closing every session and logging in again (RF-47). */
    public function test_the_choice_survives_logging_out_and_in(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test');
        $token = $this->login('ana@barberhub.test');
        $this->update($token, ['recordatorios_correo' => false])->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)->deleteJson('/api/v1/tokens')->assertNoContent();

        $again = $this->login('ana@barberhub.test');
        $this->assertNotSame($token, $again);
        $this->show($again)->assertOk()->assertJsonPath('data.recordatorios_correo', false);
    }

    /** The choice is of one account: another person's stays as it was, and only the own preference changes. */
    public function test_only_the_own_account_changes(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test');
        $this->account('Luis Vega', 'luis@barberhub.test');
        $token = $this->login('ana@barberhub.test');

        $this->update($token, ['recordatorios_correo' => false])->assertOk();

        $this->assertFalse(UserModel::query()->where('email', 'ana@barberhub.test')->sole()->reminders_enabled);
        $this->assertTrue(UserModel::query()->where('email', 'luis@barberhub.test')->sole()->reminders_enabled);
        $this->assertSame('Ana Mora', UserModel::query()->where('email', 'ana@barberhub.test')->sole()->name);
    }

    /**
     * Only a real boolean is accepted: a missing field, null, text, numbers and lists are refused on the field.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidBodies(): array
    {
        return [
            'missing' => [[]],
            'null' => [['recordatorios_correo' => null]],
            'text' => [['recordatorios_correo' => 'true']],
            'number one' => [['recordatorios_correo' => 1]],
            'number zero' => [['recordatorios_correo' => 0]],
            'text one' => [['recordatorios_correo' => '1']],
            'list' => [['recordatorios_correo' => [true]]],
        ];
    }

    /**
     * A value that is not a boolean is a 422 `datos-invalidos` on `recordatorios_correo` and the account stays as it was.
     *
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidBodies')]
    public function test_a_value_that_is_not_a_boolean_is_rejected(array $body): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test');
        $token = $this->login('ana@barberhub.test');

        $this->update($token, $body)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/datos-invalidos')
            ->assertJsonStructure(['errores' => ['recordatorios_correo']]);

        $this->assertTrue(UserModel::query()->sole()->reminders_enabled);
    }

    /** Nothing but the choice is read from the body: the account, the barbershop and the role cannot be sent. */
    public function test_other_fields_of_the_body_are_ignored(): void
    {
        $ana = $this->account('Ana Mora', 'ana@barberhub.test');
        $luis = $this->account('Luis Vega', 'luis@barberhub.test');
        $token = $this->login('ana@barberhub.test');

        $this->update($token, ['recordatorios_correo' => false, 'id' => $luis, 'usuario' => $luis, 'barberia' => $luis, 'nombre' => 'Otra', 'correo' => 'otra@barberhub.test'])
            ->assertOk()
            ->assertExactJson(['data' => ['recordatorios_correo' => false]]);

        $this->assertTrue(UserModel::query()->findOrFail($luis)->reminders_enabled);
        $ana = UserModel::query()->findOrFail($ana);
        $this->assertFalse($ana->reminders_enabled);
        $this->assertSame('Ana Mora', $ana->name);
        $this->assertSame('ana@barberhub.test', $ana->email);
    }

    /** Without a session both routes answer 401. */
    public function test_without_a_session_it_answers_unauthenticated(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertJsonPath('type', 'http://localhost/problemas/no-autenticado');
        $this->putJson(self::ENDPOINT, ['recordatorios_correo' => false])->assertUnauthorized();
    }

    /** A person with a temporary password uses no route but the mandatory change: both answer 403 `contrasena-temporal` (UP-04). */
    public function test_a_temporary_password_is_refused(): void
    {
        $this->account('Kevin Solano', 'kevin@barberhub.test', temporary: true, password: 'Temporal#2026');
        $token = $this->login('kevin@barberhub.test', 'Temporal#2026');

        foreach ([
            fn (): TestResponse => $this->show($token),
            fn (): TestResponse => $this->update($token, ['recordatorios_correo' => false]),
        ] as $call) {
            Auth::forgetGuards();

            $call()->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/contrasena-temporal');
        }

        $this->assertTrue(UserModel::query()->sole()->reminders_enabled);
    }

    /** Insert an account whose password is the given one. */
    private function account(string $name, string $email, bool $temporary = false, string $password = self::PASSWORD): string
    {
        $user = UserModel::query()->create([
            'name' => $name,
            'email' => $email,
            'phone' => $temporary ? null : '8888-0000',
            'password' => Hash::make($password),
            'must_change_password' => $temporary,
            'terms_accepted_at' => $temporary ? null : now(),
            'privacy_accepted_at' => $temporary ? null : now(),
        ]);

        return (string) $user->id;
    }

    /** Log in through the API and return the plain text token. */
    private function login(string $email, string $password = self::PASSWORD): string
    {
        return $this->postJson('/api/v1/tokens', ['correo' => $email, 'contrasena' => $password, 'dispositivo' => 'Celular'])
            ->assertCreated()
            ->json('data.token');
    }

    /**
     * Read the preferences with a token, forgetting the user the guards kept from the previous request.
     *
     * @return TestResponse<Response>
     */
    private function show(string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson(self::ENDPOINT);
    }

    /**
     * Change the preferences with a token, forgetting the user the guards kept from the previous request.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function update(string $token, array $payload): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->putJson(self::ENDPOINT, $payload);
    }
}
