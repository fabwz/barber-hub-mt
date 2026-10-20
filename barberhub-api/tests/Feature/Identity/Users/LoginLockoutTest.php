<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class LoginLockoutTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/tokens';

    private const string PASSWORD = 'Clave#segura1';

    /** Freeze the clock and create the account the scenarios try to enter. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        UserModel::query()->create([
            'name' => 'Ana Mora',
            'email' => 'ana@barberhub.test',
            'password' => Hash::make(self::PASSWORD),
        ]);
    }

    /** The sixth attempt after five failures in a row is refused with Retry-After and the minutes, even with the right password. */
    public function test_sixth_attempt_is_locked_even_with_the_right_password(): void
    {
        $this->failTimes(5);

        $this->attempt(self::PASSWORD)
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After', '900')
            ->assertJsonPath('type', 'http://localhost/problemas/demasiadas-solicitudes')
            ->assertJsonPath('status', 429)
            ->assertJsonPath('detail', 'Demasiados intentos de ingreso. Intente de nuevo en 15 minutos.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /** The fifth failure still answers invalid credentials: the lock starts after it. */
    public function test_fifth_failure_still_answers_invalid_credentials(): void
    {
        $this->failTimes(4);

        $this->attempt('OtraClave#2')->assertUnauthorized();
    }

    /** The minutes left are announced in the singular and in the plural. */
    public function test_remaining_minutes_use_the_singular_and_the_plural(): void
    {
        $this->failTimes(5);

        $this->travel(14)->minutes();
        $this->attempt(self::PASSWORD)
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('detail', 'Demasiados intentos de ingreso. Intente de nuevo en 1 minuto.');

        $this->travelTo(now()->subMinutes(14)->addMinute());
        $this->attempt(self::PASSWORD)
            ->assertStatus(429)
            ->assertJsonPath('detail', 'Demasiados intentos de ingreso. Intente de nuevo en 14 minutos.');
    }

    /** The 15 minutes run from the fifth failure, not from the first. */
    public function test_lock_lasts_fifteen_minutes_from_the_fifth_failure(): void
    {
        $this->failTimes(1);
        $this->travel(10)->minutes();
        $this->failTimes(4);

        $this->travel(14)->minutes();
        $this->attempt(self::PASSWORD)->assertStatus(429);

        $this->travel(1)->minutes();
        $this->attempt(self::PASSWORD)->assertCreated();
    }

    /** Attempts made during the lock do not extend it. */
    public function test_attempts_during_the_lock_do_not_extend_it(): void
    {
        $this->failTimes(5);

        $this->travel(10)->minutes();
        $this->attempt('OtraClave#2')->assertStatus(429);

        $this->travel(5)->minutes();
        $this->attempt(self::PASSWORD)->assertCreated();
    }

    /** After the lock expires, five new failures are needed to lock again. */
    public function test_lock_needs_five_new_failures_after_it_expires(): void
    {
        $this->failTimes(5);
        $this->travel(15)->minutes();

        $this->failTimes(4);
        $this->attempt('OtraClave#2')->assertUnauthorized();
        $this->attempt(self::PASSWORD)->assertStatus(429);
    }

    /** A correct login resets the counter: four failures, a success and four more do not lock. */
    public function test_successful_login_resets_the_counter(): void
    {
        $this->failTimes(4);
        $this->attempt(self::PASSWORD)->assertCreated();
        $this->failTimes(4);

        $this->attempt(self::PASSWORD)->assertCreated();
    }

    /** Failures expire 15 minutes after the first one, so isolated failures are forgotten. */
    public function test_isolated_failures_are_forgotten(): void
    {
        $this->failTimes(4);
        $this->travel(16)->minutes();
        $this->failTimes(4);

        $this->attempt(self::PASSWORD)->assertCreated();
    }

    /** An unknown email counts too, so the lock does not reveal whether the email exists. */
    public function test_unknown_email_is_locked_in_the_same_way(): void
    {
        $this->failTimes(5, 'nadie@barberhub.test');

        $this->attempt(self::PASSWORD, 'nadie@barberhub.test')
            ->assertStatus(429)
            ->assertHeader('Retry-After', '900');
    }

    /** The email is compared lowercased and trimmed, however it is spelled. */
    public function test_email_spelling_does_not_escape_the_lock(): void
    {
        $this->failTimes(5, ' ANA@BarberHub.test ');

        $this->attempt(self::PASSWORD)->assertStatus(429);
    }

    /** The lock is per email and address: another address or another email can still try. */
    public function test_lock_is_per_email_and_address(): void
    {
        $this->failTimes(5);

        $this->attempt(self::PASSWORD, 'ana@barberhub.test', '198.51.100.9')->assertCreated();
        $this->attempt('OtraClave#2', 'otra@barberhub.test')->assertUnauthorized();
    }

    /** Fail a number of times with a wrong password. */
    private function failTimes(int $times, string $email = 'ana@barberhub.test'): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->attempt('OtraClave#2', $email)->assertUnauthorized();
        }
    }

    /**
     * Try to log in from an address.
     *
     * @return TestResponse<JsonResponse>
     */
    private function attempt(string $password, string $email = 'ana@barberhub.test', string $ip = '203.0.113.7'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson(self::ENDPOINT, [
            'correo' => $email,
            'contrasena' => $password,
            'dispositivo' => 'Tablet del local',
        ]);
    }
}
