<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** A person sees and corrects the name, phone and email of their own account only (RF-45, CL-02, CL-03, UP-04). */
final class OwnAccountTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/cuenta';

    private const string PASSWORD = 'Clave#segura1';

    private const string TEMPORARY = 'Temporal#2026';

    /** The account answers its own data and that its password is not temporary. */
    public function test_the_person_sees_their_own_account(): void
    {
        $ana = $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $this->account('Luis Vega', 'luis@barberhub.test', '7777-1111');
        $token = $this->login('ana@barberhub.test', self::PASSWORD);

        $this->show($token)
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $ana,
                'nombre' => 'Ana Mora',
                'telefono' => '8888-0000',
                'correo' => 'ana@barberhub.test',
                'contrasena_temporal' => false,
                'operador_de_plataforma' => false,
            ]]);
    }

    /** The id is the one of the authenticated account, never another person's, and it is not in the login answer (RF-35). */
    public function test_the_id_is_the_own_one_and_only_here(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $luis = $this->account('Luis Vega', 'luis@barberhub.test', '7777-1111');
        $token = $this->login('luis@barberhub.test', self::PASSWORD);

        $this->show($token)->assertOk()->assertJsonPath('data.id', $luis);

        $login = $this->postJson('/api/v1/tokens', ['correo' => 'luis@barberhub.test', 'contrasena' => self::PASSWORD, 'dispositivo' => 'Celular'])
            ->assertCreated();
        $this->assertArrayNotHasKey('id', $login->json('data'));
    }

    /** The flag tells the web client to go to the mandatory change, and a staff account has no phone yet. */
    public function test_the_temporary_password_flag_is_true_and_readable(): void
    {
        $kevin = $this->account('Kevin Solano', 'kevin@barberhub.test', null, temporary: true, password: self::TEMPORARY);
        $token = $this->login('kevin@barberhub.test', self::TEMPORARY);

        $this->show($token)
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $kevin,
                'nombre' => 'Kevin Solano',
                'telefono' => null,
                'correo' => 'kevin@barberhub.test',
                'contrasena_temporal' => true,
                'operador_de_plataforma' => false,
            ]]);
    }

    /** The three data are corrected and nothing else of the account changes. */
    public function test_the_person_corrects_their_data(): void
    {
        $ana = $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $before = UserModel::query()->sole();
        $token = $this->login('ana@barberhub.test', self::PASSWORD);

        $this->update($token, ['nombre' => 'Ana María Mora', 'telefono' => '8999-1234', 'correo' => ' ANA.MORA@barberhub.test '])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $ana,
                'nombre' => 'Ana María Mora',
                'telefono' => '8999-1234',
                'correo' => 'ana.mora@barberhub.test',
                'contrasena_temporal' => false,
                'operador_de_plataforma' => false,
            ]]);

        $after = UserModel::query()->sole();
        $this->assertSame('Ana María Mora', $after->name);
        $this->assertSame('ana.mora@barberhub.test', $after->email);
        $this->assertSame('8999-1234', $after->phone);
        $this->assertSame($before->password, $after->password);
        $this->assertSame($before->terms_accepted_at?->toIso8601String(), $after->terms_accepted_at?->toIso8601String());
        $this->assertFalse($after->must_change_password);
    }

    /** An email that another account uses is refused on its field and the account stays as it was. */
    public function test_an_email_of_another_account_is_rejected(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $this->account('Luis Vega', 'luis@barberhub.test', '7777-1111');
        $token = $this->login('ana@barberhub.test', self::PASSWORD);

        $this->update($token, ['nombre' => 'Ana Mora', 'telefono' => '8888-0000', 'correo' => 'LUIS@barberhub.test'])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/datos-invalidos')
            ->assertJsonPath('errores.correo.0', 'Ya existe una cuenta con ese correo.');

        $this->assertSame('ana@barberhub.test', UserModel::query()->where('name', 'Ana Mora')->sole()->email);
    }

    /** Sending the own email again is not a conflict. */
    public function test_the_own_email_without_change_is_accepted(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $token = $this->login('ana@barberhub.test', self::PASSWORD);

        $this->update($token, ['nombre' => 'Ana M.', 'telefono' => '8888-0000', 'correo' => 'ana@barberhub.test'])
            ->assertOk()
            ->assertJsonPath('data.correo', 'ana@barberhub.test')
            ->assertJsonPath('data.nombre', 'Ana M.');
    }

    /** After the change the new email logs in and the old one no longer opens the account (RF-45). */
    public function test_the_old_email_stops_opening_the_account(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $token = $this->login('ana@barberhub.test', self::PASSWORD);

        $this->update($token, ['nombre' => 'Ana Mora', 'telefono' => '8888-0000', 'correo' => 'nueva@barberhub.test'])->assertOk();

        $this->postJson('/api/v1/tokens', ['correo' => 'ana@barberhub.test', 'contrasena' => self::PASSWORD, 'dispositivo' => 'Celular'])
            ->assertUnauthorized();
        $this->postJson('/api/v1/tokens', ['correo' => 'nueva@barberhub.test', 'contrasena' => self::PASSWORD, 'dispositivo' => 'Celular'])
            ->assertCreated();
    }

    /** Each datum is required and must be valid. */
    #[DataProvider('invalidData')]
    public function test_invalid_data_is_rejected(string $field, mixed $value): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $token = $this->login('ana@barberhub.test', self::PASSWORD);

        $this->update($token, [...['nombre' => 'Otra Ana', 'telefono' => '8888-0000', 'correo' => 'ana@barberhub.test'], $field => $value])
            ->assertUnprocessable()
            ->assertJsonStructure(['errores' => [$field]]);

        $this->assertSame('Ana Mora', UserModel::query()->sole()->name);
    }

    /** @return array<string, array{string, mixed}> */
    public static function invalidData(): array
    {
        return [
            'missing name' => ['nombre', null],
            'name too long' => ['nombre', str_repeat('a', 121)],
            'missing phone' => ['telefono', ''],
            'phone too long' => ['telefono', str_repeat('1', 31)],
            'missing email' => ['correo', null],
            'malformed email' => ['correo', 'no-es-un-correo'],
        ];
    }

    /** The barbershop, the role, the password and the temporary state in the body are ignored. */
    public function test_protected_fields_in_the_body_are_ignored(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $token = $this->login('ana@barberhub.test', self::PASSWORD);

        $this->update($token, [
            'nombre' => 'Ana Mora',
            'telefono' => '8888-0000',
            'correo' => 'ana@barberhub.test',
            'barberia' => '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44',
            'rol' => 'dueno',
            'contrasena' => 'Hack#2026x',
            'contrasena_temporal' => true,
            'id' => '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55',
        ])->assertOk()->assertJsonPath('data.contrasena_temporal', false);

        $user = UserModel::query()->sole();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotSame('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d55', $user->id);
    }

    /** Only the account of the session is read or edited: editing never reaches another person. */
    public function test_only_the_own_account_is_edited(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $this->account('Luis Vega', 'luis@barberhub.test', '7777-1111');
        $token = $this->login('luis@barberhub.test', self::PASSWORD);

        $this->update($token, ['nombre' => 'Luis V.', 'telefono' => '7777-0000', 'correo' => 'luis@barberhub.test'])->assertOk();

        $ana = UserModel::query()->where('email', 'ana@barberhub.test')->sole();
        $this->assertSame('Ana Mora', $ana->name);
        $this->assertSame('8888-0000', $ana->phone);
        $this->assertSame('Luis V.', UserModel::query()->where('email', 'luis@barberhub.test')->sole()->name);
        $this->assertSame('luis@barberhub.test', $this->show($token)->json('data.correo'));
    }

    /** Without a session both routes answer 401. */
    public function test_without_a_session_it_answers_unauthenticated(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized()
            ->assertJsonPath('type', 'http://localhost/problemas/no-autenticado');
        $this->putJson(self::ENDPOINT, ['nombre' => 'Ana', 'telefono' => '8888-0000', 'correo' => 'ana@barberhub.test'])
            ->assertUnauthorized();
    }

    /** A collaborator with the temporary password reads the account but cannot use any other route, edits included. */
    public function test_a_temporary_password_only_allows_reading_the_account(): void
    {
        $this->account('Kevin Solano', 'kevin@barberhub.test', null, temporary: true, password: self::TEMPORARY);
        $token = $this->login('kevin@barberhub.test', self::TEMPORARY);

        $this->show($token)->assertOk()->assertJsonPath('data.contrasena_temporal', true);

        foreach ([
            fn (): TestResponse => $this->update($token, ['nombre' => 'Kevin', 'telefono' => '8888-0000', 'correo' => 'kevin@barberhub.test']),
            fn (): TestResponse => $this->withToken($token)->getJson('/api/v1/mis-barberias'),
        ] as $call) {
            Auth::forgetGuards();

            $call()->assertForbidden()
                ->assertJsonPath('type', 'http://localhost/problemas/contrasena-temporal');
        }

        $this->assertSame('Kevin Solano', UserModel::query()->sole()->name);
    }

    /** The contact data of the account never reach the logs. */
    public function test_the_contact_data_are_not_logged(): void
    {
        $this->account('Ana Mora', 'ana@barberhub.test', '8888-0000');
        $token = $this->login('ana@barberhub.test', self::PASSWORD);
        $logged = [];
        Log::listen(function ($event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->update($token, ['nombre' => 'Ana Secreta', 'telefono' => '8123-9999', 'correo' => 'secreta@barberhub.test'])->assertOk();
        $this->show($token)->assertOk();

        foreach ($logged as $line) {
            $this->assertStringNotContainsString('secreta@barberhub.test', $line);
            $this->assertStringNotContainsString('8123-9999', $line);
            $this->assertStringNotContainsString('Ana Secreta', $line);
        }
    }

    /** Insert an account whose password is the given one. */
    private function account(string $name, string $email, ?string $phone, bool $temporary = false, string $password = self::PASSWORD): string
    {
        $user = UserModel::query()->create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($password),
            'must_change_password' => $temporary,
            'terms_accepted_at' => $temporary ? null : now(),
            'privacy_accepted_at' => $temporary ? null : now(),
        ]);

        return (string) $user->id;
    }

    /** Log in through the API and return the plain text token. */
    private function login(string $email, string $password): string
    {
        return $this->postJson('/api/v1/tokens', ['correo' => $email, 'contrasena' => $password, 'dispositivo' => 'Celular'])
            ->assertCreated()
            ->json('data.token');
    }

    /**
     * Read the account with a token, forgetting the user the guards kept from the previous request.
     *
     * @return TestResponse<Response>
     */
    private function show(string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->withToken($token)->getJson(self::ENDPOINT);
    }

    /**
     * Correct the account with a token, forgetting the user the guards kept from the previous request.
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
