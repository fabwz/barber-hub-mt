<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use DateTimeImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class RegisterCustomerTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/cuentas';

    private const string PASSWORD = 'Clave#segura1';

    /** Registering answers 201 with the account, both acceptance dates, and neither the password nor its hash. */
    public function test_account_is_registered(): void
    {
        $this->app->instance(Clock::class, new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-19 10:00:00-06:00');
            }
        });

        $response = $this->postJson(self::ENDPOINT, $this->payload());

        $response->assertCreated()
            ->assertExactJson([
                'data' => [
                    'id' => UserModel::query()->sole()->id,
                    'nombre' => 'Ana Mora',
                    'correo' => 'ana@barberhub.test',
                    'telefono' => '8888-0000',
                    'acepta_terminos' => '2026-10-19T10:00:00-06:00',
                    'acepta_privacidad' => '2026-10-19T10:00:00-06:00',
                ],
            ]);

        $user = UserModel::query()->sole();
        $this->assertTrue($user->is_active);
        $this->assertSame('2026-10-19 10:00:00', $user->terms_accepted_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-19 10:00:00', $user->privacy_accepted_at?->format('Y-m-d H:i:s'));

        $body = $response->getContent();
        $this->assertStringNotContainsString(self::PASSWORD, (string) $body);
        $this->assertStringNotContainsString($user->password, (string) $body);
        $this->assertStringNotContainsStringIgnoringCase('contrasena', (string) $body);
    }

    /** The password is stored as a hash that matches it, and the email is normalised (CL-01). */
    public function test_password_is_stored_as_a_hash_and_email_is_normalised(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(['correo' => '  Ana@BarberHub.TEST ']))->assertCreated();

        $user = UserModel::query()->sole();

        $this->assertSame('ana@barberhub.test', $user->email);
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    /** The password travels untouched: spaces at its ends are part of it. */
    public function test_password_is_not_trimmed(): void
    {
        $password = ' Clave#segura1 ';

        $this->postJson(self::ENDPOINT, $this->payload(['contrasena' => $password]))->assertCreated();

        $this->assertTrue(Hash::check($password, UserModel::query()->sole()->password));
    }

    /** Registering creates the account and nothing else: no membership exists until the first use in a barbershop (UP-09). */
    public function test_registering_creates_no_membership(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertCreated();

        $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))
            ->pluck('tablename')
            ->reject(fn (string $table): bool => $table === 'users' || $table === 'migrations');

        foreach ($tables as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    /** An email that already has an account is refused whatever its spelling, and the first account is kept. */
    public function test_repeated_email_is_refused(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload())->assertCreated();

        $this->postJson(self::ENDPOINT, $this->payload(['correo' => ' ANA@barberhub.test ', 'nombre' => 'Otra Ana']))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', rtrim((string) config('app.url'), '/').'/problemas/datos-invalidos')
            ->assertJsonPath('detail', 'Ya existe una cuenta con ese correo.');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Ana Mora', UserModel::query()->sole()->name);
    }

    /**
     * A registration without the two acceptances is refused, and each one is required on its own.
     *
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $fields
     */
    #[DataProvider('missingAcceptances')]
    public function test_registration_without_both_acceptances_is_refused(array $changes, array $fields): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload($changes))->assertUnprocessable();

        $this->assertSame($fields, array_keys($response->json('errores')));
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Payload changes that leave an acceptance out, false or declined, and the fields reported.
     *
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function missingAcceptances(): array
    {
        return [
            'terms missing' => [['acepta_terminos' => null], ['acepta_terminos']],
            'terms declined' => [['acepta_terminos' => false], ['acepta_terminos']],
            'privacy missing' => [['acepta_privacidad' => null], ['acepta_privacidad']],
            'privacy declined' => [['acepta_privacidad' => false], ['acepta_privacidad']],
            'both missing' => [['acepta_terminos' => null, 'acepta_privacidad' => null], ['acepta_terminos', 'acepta_privacidad']],
        ];
    }

    /** A password that misses a single requirement is refused and the form is told which one. */
    #[DataProvider('passwordsMissingOneRequirement')]
    public function test_password_missing_one_requirement_is_refused(string $password, string $message): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload(['contrasena' => $password]))->assertUnprocessable();

        $this->assertSame([$message], $response->json('errores.contrasena'));
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Passwords that break exactly one requirement of SE-07, with the message the form shows.
     *
     * @return array<string, array{string, string}>
     */
    public static function passwordsMissingOneRequirement(): array
    {
        return [
            'too short' => ['Ab1!', 'La contraseña debe tener al menos 8 caracteres.'],
            'without uppercase' => ['abcdef1!', 'La contraseña debe incluir al menos una letra mayúscula.'],
            'without lowercase' => ['ABCDEF1!', 'La contraseña debe incluir al menos una letra minúscula.'],
            'without number' => ['Abcdefg!', 'La contraseña debe incluir al menos un número.'],
            'without symbol' => ['Abcdefg1', 'La contraseña debe incluir al menos un signo especial.'],
        ];
    }

    /** A password that misses several requirements gets all of them at once, not one per attempt. */
    public function test_password_missing_several_requirements_lists_them_all(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload(['contrasena' => 'abc']))->assertUnprocessable();

        $this->assertSame([
            'La contraseña debe tener al menos 8 caracteres.',
            'La contraseña debe incluir al menos una letra mayúscula.',
            'La contraseña debe incluir al menos un número.',
            'La contraseña debe incluir al menos un signo especial.',
        ], $response->json('errores.contrasena'));
    }

    /** A missing or empty password is reported as required, not as every requirement. */
    public function test_missing_password_is_reported_as_required(): void
    {
        $this->postJson(self::ENDPOINT, $this->payload(['contrasena' => null]))
            ->assertUnprocessable()
            ->assertJsonPath('errores.contrasena', ['El campo contraseña es obligatorio.']);
    }

    /** Every other field is required and has its limit. */
    #[DataProvider('invalidFields')]
    public function test_invalid_fields_are_refused(string $field, mixed $value): void
    {
        $this->postJson(self::ENDPOINT, $this->payload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field], 'errores');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * Values that break the shape of a field.
     *
     * @return array<string, array{string, mixed}>
     */
    public static function invalidFields(): array
    {
        return [
            'missing name' => ['nombre', null],
            'long name' => ['nombre', str_repeat('a', 121)],
            'missing email' => ['correo', null],
            'malformed email' => ['correo', 'ana.barberhub.test'],
            'missing phone' => ['telefono', null],
            'long phone' => ['telefono', str_repeat('1', 31)],
        ];
    }

    /** Neither the response of a refusal nor the log contains the password. */
    public function test_password_never_reaches_a_response_or_the_log(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $this->postJson(self::ENDPOINT, $this->payload())->assertCreated();
        $duplicate = $this->postJson(self::ENDPOINT, $this->payload())->assertUnprocessable();
        $weak = $this->postJson(self::ENDPOINT, $this->payload(['correo' => 'otra@barberhub.test', 'contrasena' => 'Weak-pass']))
            ->assertUnprocessable();

        foreach ([$duplicate->getContent(), $weak->getContent(), ...$logged] as $text) {
            $this->assertStringNotContainsString(self::PASSWORD, (string) $text);
            $this->assertStringNotContainsString('Weak-pass', (string) $text);
        }
    }

    /**
     * A valid registration, with the changes of a scenario applied.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(array $changes = []): array
    {
        return [
            'nombre' => 'Ana Mora',
            'correo' => 'ana@barberhub.test',
            'telefono' => '8888-0000',
            'contrasena' => self::PASSWORD,
            'acepta_terminos' => true,
            'acepta_privacidad' => true,
            ...$changes,
        ];
    }
}
