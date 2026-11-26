<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Illuminate\Testing\TestResponse;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Notifications\Notices\Infrastructure\Jobs\SendPasswordResetNoticeJob;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The request for the link to reset a password (RF-41): the same answer for every email, the limits of SE-06 and the work left to the queue. */
final class PasswordResetRequestTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string ENDPOINT = '/api/v1/contrasena/recuperacion';

    private QueueFake $queue;

    /** Ana has an account; nobody else does. The clock is frozen so the windows of the limits are exact. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->queue = Queue::fake([SendPasswordResetNoticeJob::class]);
        UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => Hash::make('Clave#segura1')]);
    }

    /** An email with an account and one without get the same status, body and content type, and both leave the same task in the queue (SE-06). */
    public function test_the_answer_is_identical_whether_or_not_the_email_exists(): void
    {
        $existing = $this->ask('ana@barberhub.test');
        $unknown = $this->ask('nadie@barberhub.test');

        $existing->assertStatus(202);
        $unknown->assertStatus(202);
        $this->assertSame($existing->getContent(), $unknown->getContent());
        $this->assertSame('', (string) $existing->getContent());
        $this->assertSame($existing->headers->get('Content-Type'), $unknown->headers->get('Content-Type'));
        $this->queue->assertPushed(SendPasswordResetNoticeJob::class, 2);
        $this->queue->assertPushed(SendPasswordResetNoticeJob::class, fn (SendPasswordResetNoticeJob $job): bool => $job->email === 'ana@barberhub.test');
        $this->queue->assertPushed(SendPasswordResetNoticeJob::class, fn (SendPasswordResetNoticeJob $job): bool => $job->email === 'nadie@barberhub.test');
    }

    /** A deactivated account is answered like any other: nothing in the answer tells it apart. */
    public function test_a_deactivated_account_gets_the_same_answer(): void
    {
        UserModel::query()->where('email', 'ana@barberhub.test')->update(['is_active' => false]);

        $this->ask('ana@barberhub.test')->assertStatus(202)->assertNoContent(202);
    }

    /** The email is written in any case and with spaces; the task carries it normalised. */
    public function test_the_email_is_normalised(): void
    {
        $this->ask('  ANA@BarberHub.test ')->assertStatus(202);

        $this->queue->assertPushed(SendPasswordResetNoticeJob::class, fn (SendPasswordResetNoticeJob $job): bool => $job->email === 'ana@barberhub.test');
    }

    /** The sixth request of the hour for one email is refused, from whatever address (SE-06). */
    public function test_the_sixth_request_for_an_email_is_refused(): void
    {
        foreach (range(1, 5) as $n) {
            $this->ask('ana@barberhub.test', "10.0.0.{$n}")->assertStatus(202);
        }

        $this->ask('ana@barberhub.test', '10.0.0.6')
            ->assertStatus(429)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After', '3600')
            ->assertJsonPath('type', 'http://localhost/problemas/demasiadas-solicitudes')
            ->assertJsonPath('status', 429)
            ->assertJsonPath('detail', 'Demasiadas solicitudes para restablecer la contraseña. Intente de nuevo en 60 minutos.');

        $this->queue->assertPushed(SendPasswordResetNoticeJob::class, 5);
    }

    /** The sixth request of the hour from one address is refused, whatever the email (SE-06). */
    public function test_the_sixth_request_from_an_address_is_refused(): void
    {
        foreach (range(1, 5) as $n) {
            $this->ask("persona{$n}@barberhub.test", '10.0.0.9')->assertStatus(202);
        }

        $this->ask('persona6@barberhub.test', '10.0.0.9')
            ->assertStatus(429)
            ->assertJsonPath('type', 'http://localhost/problemas/demasiadas-solicitudes');

        // Another address is not affected by it.
        $this->ask('persona6@barberhub.test', '10.0.0.10')->assertStatus(202);
        $this->queue->assertPushed(SendPasswordResetNoticeJob::class, 6);
    }

    /** The limit does not depend on the account existing: the sixth request for an unknown email is refused too. */
    public function test_the_limit_is_the_same_for_an_unknown_email(): void
    {
        foreach (range(1, 5) as $n) {
            $this->ask('nadie@barberhub.test', "10.0.1.{$n}")->assertStatus(202);
        }

        $this->ask('nadie@barberhub.test', '10.0.1.6')->assertStatus(429);
    }

    /** A refused request is not counted, and the window is of one hour from the first request. */
    public function test_the_wait_counts_down_and_ends_after_an_hour(): void
    {
        foreach (range(1, 5) as $n) {
            $this->ask('ana@barberhub.test', "10.0.2.{$n}")->assertStatus(202);
        }

        $this->travel(30)->minutes();
        $this->ask('ana@barberhub.test', '10.0.2.6')->assertStatus(429)->assertHeader('Retry-After', '1800');

        $this->travel(29)->minutes();
        $this->ask('ana@barberhub.test', '10.0.2.6')->assertStatus(429)->assertHeader('Retry-After', '60');

        $this->travel(2)->minutes();
        $this->ask('ana@barberhub.test', '10.0.2.6')->assertStatus(202);
    }

    /** An email that is missing or malformed is a validation error, with nothing queued. */
    public function test_the_email_is_validated(): void
    {
        $this->postJson(self::ENDPOINT, [])->assertUnprocessable()->assertJsonValidationErrors('correo', 'errores');
        $this->postJson(self::ENDPOINT, ['correo' => 'no-es-un-correo'])->assertUnprocessable()->assertJsonValidationErrors('correo', 'errores');

        $this->queue->assertNothingPushed();
    }

    /** @return TestResponse<JsonResponse> */
    private function ask(string $email, string $address = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])->postJson(self::ENDPOINT, ['correo' => $email]);
    }
}
