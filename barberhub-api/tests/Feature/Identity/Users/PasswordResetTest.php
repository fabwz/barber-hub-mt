<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Notifications\Notices\Infrastructure\Mail\NoticeMail;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;
use Throwable;

/**
 * The whole recovery of a password, from the request to the new login, with the queue running in the same process and the mail faked
 * (RF-41, SE-05, SE-06, SE-07, NO-10). Nothing leaves the process.
 */
final class PasswordResetTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private const string REQUEST = '/api/v1/contrasena/recuperacion';

    private const string RESET = '/api/v1/contrasena/restablecimiento';

    private const string OLD = 'Clave#segura1';

    private const string NEW = 'Nueva#Clave9';

    private MutableClock $clock;

    private UserModel $ana;

    /** @var list<string> */
    private array $logged = [];

    /** Ana has an account and turned her reminders off. The clock is the one of the test, and the sending is not held back by the limit of the provider. */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->app->make('config')->set('notices.mail_per_second', 1000);
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->ana = UserModel::query()->create([
            'name' => 'Ana Mora',
            'email' => 'ana@barberhub.test',
            'password' => Hash::make(self::OLD),
            'reminders_enabled' => false,
        ]);

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);

            $exception = $event->context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                $this->logged[] = (string) $exception;
            }
        });
    }

    /** The email reaches the person with the link to the web client, valid 60 minutes, and the new password works while the old one does not (RF-41). */
    public function test_the_whole_recovery_changes_the_password(): void
    {
        $token = $this->requestLink();

        $mail = Mail::sent(NoticeMail::class)->last();
        $this->assertInstanceOf(NoticeMail::class, $mail);
        $this->assertTrue($mail->hasTo('ana@barberhub.test'));
        $this->assertSame('Restablezca su contraseña de BarberHub', $mail->envelope()->subject);
        $this->assertSame('http://localhost:5173/restablecer-contrasena?token='.$token, $mail->notice->actionUrl);
        $mail->assertSeeInHtml('Hola, Ana Mora.');
        $mail->assertSeeInHtml('Crear nueva contraseña');
        $mail->assertSeeInText('El enlace es válido por 60 minutos y solo se puede usar una vez.');
        $mail->assertSeeInText('http://localhost:5173/restablecer-contrasena?token='.$token);
        $mail->assertSeeInText('Este mensaje fue enviado por BarberHub.');
        $mail->assertDontSeeInText('en nombre de');

        $this->reset($token, self::NEW)->assertNoContent();

        $this->login(self::OLD)->assertUnauthorized();
        $this->login(self::NEW)->assertCreated();
    }

    /** The recovery email is sent although the customer turned the reminders off: it protects the account (NO-10). */
    public function test_the_email_is_sent_even_with_the_reminders_off(): void
    {
        $this->assertFalse($this->ana->fresh()?->reminders_enabled);

        $this->requestLink();

        Mail::assertSent(NoticeMail::class, 1);
    }

    /** Only the hash of the token is stored; the token itself is in no column (SE-06). */
    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $token = $this->requestLink();

        $row = DB::table('password_reset_tokens')->where('user_id', $this->ana->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertDatabaseMissing('password_reset_tokens', ['token_hash' => $token]);
        $this->assertStringNotContainsString($token, json_encode((array) $row, JSON_THROW_ON_ERROR));
    }

    /** The link works up to minute 59 and stops working at minute 60 (SE-06). */
    public function test_the_link_expires_after_sixty_minutes(): void
    {
        $token = $this->requestLink();
        $this->clock->moveTo($this->clock->now()->modify('+60 minutes'));

        $this->reset($token, self::NEW)
            ->assertUnprocessable()
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonPath('errores.token.0', 'El enlace para restablecer la contraseña no es válido o ya venció. Solicite uno nuevo.');
        $this->login(self::NEW)->assertUnauthorized();
    }

    /** One minute before the end the link still works. */
    public function test_the_link_works_until_the_last_minute(): void
    {
        $token = $this->requestLink();
        $this->clock->moveTo($this->clock->now()->modify('+59 minutes 59 seconds'));

        $this->reset($token, self::NEW)->assertNoContent();
    }

    /** The link works once: the second use is refused like an unknown one (SE-06). */
    public function test_the_link_cannot_be_used_twice(): void
    {
        $token = $this->requestLink();

        $this->reset($token, self::NEW)->assertNoContent();
        $this->reset($token, 'Otra#Clave88')->assertUnprocessable()->assertJsonPath('errores.token.0', 'El enlace para restablecer la contraseña no es válido o ya venció. Solicite uno nuevo.');

        $this->login(self::NEW)->assertCreated();
        $this->login('Otra#Clave88')->assertUnauthorized();
    }

    /** Asking again replaces the link: only the last one sent works (SE-06). */
    public function test_a_new_request_invalidates_the_previous_link(): void
    {
        $first = $this->requestLink();
        $second = $this->requestLink();
        $this->assertNotSame($first, $second);

        $this->reset($first, self::NEW)->assertUnprocessable();
        $this->reset($second, self::NEW)->assertNoContent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /** Every session and token of the account stops working, from any device (SE-06). */
    public function test_every_session_and_token_is_closed(): void
    {
        $phone = (string) $this->login(self::OLD)->json('data.token');
        $laptop = (string) $this->login(self::OLD)->json('data.token');
        $this->assertSame(2, DB::table('personal_access_tokens')->count());
        $this->withToken($phone)->getJson('/api/v1/cuenta')->assertOk();
        Auth::forgetGuards();

        $this->reset($this->requestLink(), self::NEW)->assertNoContent();

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        Auth::forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/cuenta')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($laptop)->getJson('/api/v1/cuenta')->assertUnauthorized();
    }

    /** The tokens of another account are not touched. */
    public function test_the_sessions_of_other_accounts_stay_open(): void
    {
        UserModel::query()->create(['name' => 'Luis Mora', 'email' => 'luis@barberhub.test', 'password' => Hash::make(self::OLD)]);
        $luis = (string) $this->postJson('/api/v1/tokens', ['correo' => 'luis@barberhub.test', 'contrasena' => self::OLD, 'dispositivo' => 'Celular'])->json('data.token');

        $this->reset($this->requestLink(), self::NEW)->assertNoContent();

        Auth::forgetGuards();
        $this->withToken($luis)->getJson('/api/v1/cuenta')->assertOk();
    }

    /** A password that misses requirements is refused with the whole list and the link is not spent (SE-07). */
    public function test_a_weak_password_is_refused_and_the_link_survives(): void
    {
        $token = $this->requestLink();

        $this->reset($token, 'debil')
            ->assertUnprocessable()
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonCount(4, 'errores.contrasena_nueva');
        $this->login('debil')->assertUnauthorized();

        $this->reset($token, self::NEW)->assertNoContent();
    }

    /** The reset needs both fields. */
    public function test_the_token_and_the_password_are_required(): void
    {
        $this->postJson(self::RESET, [])->assertUnprocessable()->assertJsonValidationErrors(['token', 'contrasena_nueva'], 'errores');
    }

    /** A token nobody issued is refused like an expired one: nothing tells the cases apart. */
    public function test_an_unknown_token_is_refused(): void
    {
        $this->reset(str_repeat('a', 64), self::NEW)
            ->assertUnprocessable()
            ->assertJsonPath('errores.token.0', 'El enlace para restablecer la contraseña no es válido o ya venció. Solicite uno nuevo.');
    }

    /** A temporary password (UP-04) stops being temporary: the person can use the application right away. */
    public function test_a_temporary_password_stops_being_temporary(): void
    {
        UserModel::query()->whereKey($this->ana->id)->update(['must_change_password' => true]);

        $this->reset($this->requestLink(), self::NEW)->assertNoContent();

        $this->assertFalse((bool) DB::table('users')->where('id', $this->ana->id)->value('must_change_password'));
        $token = (string) $this->login(self::NEW)->json('data.token');
        $this->withToken($token)->getJson('/api/v1/cuenta')->assertOk()->assertJsonPath('data.contrasena_temporal', false);
    }

    /** The new password is stored hashed (SE-01). */
    public function test_the_new_password_is_stored_hashed(): void
    {
        $this->reset($this->requestLink(), self::NEW)->assertNoContent();

        $stored = (string) DB::table('users')->where('id', $this->ana->id)->value('password');
        $this->assertNotSame(self::NEW, $stored);
        $this->assertTrue(Hash::check(self::NEW, $stored));
    }

    /** An email without an account, or a deactivated one, gets the same answer and no message. */
    public function test_no_email_is_sent_for_an_unknown_or_deactivated_account(): void
    {
        $this->postJson(self::REQUEST, ['correo' => 'nadie@barberhub.test'])->assertStatus(202);
        UserModel::query()->whereKey($this->ana->id)->update(['is_active' => false]);
        $this->postJson(self::REQUEST, ['correo' => 'ana@barberhub.test'])->assertStatus(202);

        Mail::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /** Neither the token, nor the new password, nor the email reach the log, in a success or in the refusals (SE-01, SE-02). */
    public function test_nothing_secret_reaches_the_log(): void
    {
        $token = $this->requestLink();
        $this->reset('b'.substr($token, 1), self::NEW)->assertUnprocessable();
        $this->reset($token, 'debil')->assertUnprocessable();
        $this->reset($token, self::NEW)->assertNoContent();
        $this->reset($token, self::NEW)->assertUnprocessable();

        foreach ([$token, self::NEW, 'debil', 'ana@barberhub.test'] as $secret) {
            foreach ($this->logged as $line) {
                $this->assertStringNotContainsString($secret, $line);
            }
        }
    }

    /** The task that waits in the real queue is encrypted: the table of jobs does not show the email, and the token does not exist yet (SE-06). */
    public function test_the_queued_task_shows_neither_the_email_nor_a_token(): void
    {
        $this->app->make('config')->set('queue.default', 'database');

        $this->postJson(self::REQUEST, ['correo' => 'ana@barberhub.test'])->assertStatus(202);

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringContainsString('SendPasswordResetNoticeJob', $payload);
        $this->assertStringNotContainsString('ana@barberhub', $payload);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Mail::assertNothingSent();
    }

    /** Ask for the link and answer the token that went in the email. */
    private function requestLink(): string
    {
        $this->postJson(self::REQUEST, ['correo' => 'ana@barberhub.test'])->assertStatus(202);

        $mail = Mail::sent(NoticeMail::class)->last();
        $this->assertInstanceOf(NoticeMail::class, $mail);
        parse_str((string) parse_url((string) $mail->notice->actionUrl, PHP_URL_QUERY), $query);

        $token = $query['token'] ?? null;
        $this->assertIsString($token);

        return $token;
    }

    /** @return TestResponse<JsonResponse> */
    private function reset(string $token, string $password): TestResponse
    {
        return $this->postJson(self::RESET, ['token' => $token, 'contrasena_nueva' => $password]);
    }

    /** @return TestResponse<JsonResponse> */
    private function login(string $password): TestResponse
    {
        return $this->postJson('/api/v1/tokens', ['correo' => 'ana@barberhub.test', 'contrasena' => $password, 'dispositivo' => 'Celular']);
    }
}
