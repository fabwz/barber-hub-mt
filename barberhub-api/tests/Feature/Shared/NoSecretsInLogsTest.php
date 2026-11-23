<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;
use Throwable;

/**
 * No password, bearer token or Authorization header reaches the logs, neither after a success, a refusal nor an unexpected failure,
 * including the stack trace of an exception (SE-01, SE-02, RNF-08).
 */
final class NoSecretsInLogsTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string CURRENT = 'Clave#segura1';

    private const string NEW = 'Nueva#Clave9';

    private const string WRONG = 'Equivocada#7';

    /** @var list<string> */
    private array $logged = [];

    private string $previousIgnoreArgs = '1';

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Development servers keep the arguments of each call in the trace; the test must not depend on the ini of the machine.
        $this->previousIgnoreArgs = (string) ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);

            $exception = $event->context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                // The stack trace of the exception is what ends up in the log file, with the arguments of each call.
                $this->logged[] = (string) $exception;
            }
        });
    }

    /** A login, a change of password and the refusals of both leave no password, token or Authorization header in the log. */
    public function test_passwords_tokens_and_the_authorization_header_are_never_logged(): void
    {
        UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => Hash::make(self::CURRENT), 'is_active' => true]);

        $this->postJson('/api/v1/tokens', ['correo' => 'ana@barberhub.test', 'contrasena' => self::WRONG, 'dispositivo' => 'Celular'])->assertUnauthorized();
        $token = $this->postJson('/api/v1/tokens', ['correo' => 'ana@barberhub.test', 'contrasena' => self::CURRENT, 'dispositivo' => 'Celular'])
            ->assertCreated()
            ->json('data.token');

        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/cuenta/contrasena', ['contrasena_actual' => self::WRONG, 'contrasena_nueva' => self::NEW])->assertUnprocessable();
        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/cuenta/contrasena', ['contrasena_actual' => self::CURRENT, 'contrasena_nueva' => self::NEW])->assertNoContent();

        $this->assertNothingSecretInTheLog($token);
    }

    /** An unexpected failure in the middle of a change of password is reported without the passwords, the token or the header in its trace. */
    public function test_an_unexpected_failure_logs_no_password_token_or_header(): void
    {
        UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => Hash::make(self::CURRENT), 'is_active' => true]);
        $token = $this->postJson('/api/v1/tokens', ['correo' => 'ana@barberhub.test', 'contrasena' => self::CURRENT, 'dispositivo' => 'Celular'])
            ->assertCreated()
            ->json('data.token');

        $this->app->bind(PasswordHasher::class, fn (): PasswordHasher => new class implements PasswordHasher
        {
            public function hash(string $plainPassword): string
            {
                throw new RuntimeException('The hasher is not available.');
            }

            public function check(string $plainPassword, ?string $hash): bool
            {
                throw new RuntimeException('The hasher is not available.');
            }
        });

        Auth::forgetGuards();
        $this->withToken($token)->putJson('/api/v1/cuenta/contrasena', ['contrasena_actual' => self::CURRENT, 'contrasena_nueva' => self::NEW])->assertStatus(500);

        $this->assertNotEmpty($this->logged, 'The failure was reported to the log.');
        $this->assertNothingSecretInTheLog($token);
    }

    private function assertNothingSecretInTheLog(string $token): void
    {
        $log = implode("\n", $this->logged);
        // A Sanctum token is "<id>|<secret>"; the secret is the part that grants access.
        $secret = explode('|', $token, 2)[1];

        foreach ([self::CURRENT, self::NEW, self::WRONG, $token, $secret, 'Authorization', 'Bearer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $log, 'The log holds a password, a token or the Authorization header.');
        }
    }
}
