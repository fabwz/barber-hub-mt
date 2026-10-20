<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class IssueTokenTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/tokens';

    private const string PASSWORD = 'Clave#segura1';

    /** A correct login answers 201 with a bearer token for the named device, and nothing about roles or the password. */
    public function test_correct_login_receives_a_token(): void
    {
        $user = $this->account();

        $response = $this->postJson(self::ENDPOINT, $this->credentials(['correo' => ' ANA@BarberHub.test ']));

        $token = $response->json('data.token');
        $response
            ->assertCreated()
            ->assertExactJson(['data' => ['token' => $token, 'tipo' => 'Bearer']]);

        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'Tablet del local']);
        $this->assertTrue(PersonalAccessToken::findToken($token)?->tokenable?->is($user));
        $this->assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());
        $this->assertStringNotContainsString($user->password, (string) $response->getContent());
    }

    /** A wrong password, an unknown email and an inactive account answer the very same problem. */
    #[DataProvider('invalidCredentials')]
    public function test_invalid_credentials_answer_the_same_problem(string $email, string $password): void
    {
        $this->account();
        $this->account(['email' => 'inactiva@barberhub.test', 'is_active' => false]);

        $response = $this->postJson(self::ENDPOINT, $this->credentials(['correo' => $email, 'contrasena' => $password]));

        $response
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeaderMissing('Retry-After');

        $this->assertSame([
            'type' => 'http://localhost/problemas/credenciales-invalidas',
            'title' => 'Credenciales inválidas',
            'status' => 401,
            'detail' => 'El correo o la contraseña no son correctos.',
        ], Arr::except($response->json(), 'instance'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * Credentials that must not receive a token.
     *
     * @return array<string, array{string, string}>
     */
    public static function invalidCredentials(): array
    {
        return [
            'wrong password' => ['ana@barberhub.test', 'OtraClave#2'],
            'unknown email' => ['nadie@barberhub.test', self::PASSWORD],
            'inactive account' => ['inactiva@barberhub.test', self::PASSWORD],
        ];
    }

    /** Malformed fields are reported one by one in Spanish. */
    #[DataProvider('invalidPayloads')]
    public function test_malformed_data_is_rejected(string $field, mixed $value, string $message): void
    {
        $this->postJson(self::ENDPOINT, $this->credentials([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonPath("errores.{$field}.0", $message);
    }

    /**
     * Invalid values for a field and the message each one produces.
     *
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing email' => ['correo', null, 'El campo correo es obligatorio.'],
            'malformed email' => ['correo', 'ana.barberhub.test', 'El campo correo debe ser un correo electrónico válido.'],
            'missing password' => ['contrasena', null, 'El campo contraseña es obligatorio.'],
            'missing device' => ['dispositivo', null, 'El campo dispositivo es obligatorio.'],
            'device too long' => ['dispositivo', str_repeat('a', 101), 'El campo dispositivo no debe tener más de 100 caracteres.'],
        ];
    }

    /** The password travels untouched: spaces at its ends are part of it. */
    public function test_password_is_not_trimmed(): void
    {
        $this->account(['password' => Hash::make(' clave con espacios ')]);

        $this->postJson(self::ENDPOINT, $this->credentials(['contrasena' => ' clave con espacios ']))->assertCreated();
        $this->postJson(self::ENDPOINT, $this->credentials(['contrasena' => 'clave con espacios']))->assertUnauthorized();
    }

    /** The password never reaches the logs, neither after a success nor after a failure. */
    public function test_password_is_not_logged(): void
    {
        $this->account();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->postJson(self::ENDPOINT, $this->credentials())->assertCreated();
        $this->postJson(self::ENDPOINT, $this->credentials(['contrasena' => 'OtraClave#2']))->assertUnauthorized();

        $this->assertStringNotContainsString(self::PASSWORD, implode('', $logged));
        $this->assertStringNotContainsString('OtraClave#2', implode('', $logged));
    }

    /**
     * Create the active account ana@barberhub.test, whose password is the one of the tests.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes = []): UserModel
    {
        return UserModel::query()->create([
            'name' => 'Ana Mora',
            'email' => 'ana@barberhub.test',
            'password' => Hash::make(self::PASSWORD),
            'is_active' => true,
            ...$attributes,
        ]);
    }

    /**
     * Valid credentials with some fields replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function credentials(array $overrides = []): array
    {
        return [
            'correo' => 'ana@barberhub.test',
            'contrasena' => self::PASSWORD,
            'dispositivo' => 'Tablet del local',
            ...$overrides,
        ];
    }
}
