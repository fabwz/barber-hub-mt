<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Invitations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Notifications\Notices\Infrastructure\Mail\NoticeMail;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;
use Throwable;

/**
 * The whole invitation of a collaborator, from the owner's request to the first login of the invited person, with the queue running in the
 * same process and the mail faked (RF-06, UP-04, UP-07, SE-07, NO-03). Nothing leaves the process.
 */
final class CollaboratorInvitationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string PASSWORD = 'Clave#segura1';

    private const string ADMIN = '/api/v1/barberias/%s/administracion/invitaciones';

    private const string CONSULT = '/api/v1/invitaciones/consulta';

    private const string ACCEPT = '/api/v1/invitaciones/aceptacion';

    private MutableClock $clock;

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $foreignOwner;

    private UserModel $stranger;

    private string $haircut;

    private string $kidsHaircut;

    private string $foreignService;

    /** @var list<string> */
    private array $logged = [];

    /** Pura Navaja has an owner, a collaborator and a customer and two services; Fade Lab is another barbershop with its own owner and service. */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->app->make('config')->set('notices.mail_per_second', 1000);
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $this->foreignOwner = $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->stranger = $this->createMember('dani@barberhub.test', null, null);
        $this->haircut = $this->createService($this->puraNavaja, 'Corte clásico');
        $this->kidsHaircut = $this->createService($this->puraNavaja, 'Corte infantil');
        $this->foreignService = $this->createService($this->fadeLab, 'Corte de Fade Lab');

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR);

            $exception = $event->context['exception'] ?? null;
            if ($exception instanceof Throwable) {
                $this->logged[] = (string) $exception;
            }
        });
    }

    /** Without a session, every operation of the owner answers the unauthenticated problem. */
    public function test_administration_requires_a_session(): void
    {
        $this->getJson($this->admin($this->puraNavaja))->assertUnauthorized();
        $this->postJson($this->admin($this->puraNavaja), $this->payload())->assertUnauthorized();
        $this->deleteJson($this->admin($this->puraNavaja).'/'.Str::uuid7())->assertUnauthorized();
    }

    /** Only the owner invites: a collaborator and a customer get 403, a person of another barbershop or with no role gets the 404 of a barbershop that does not exist. */
    public function test_only_the_owner_of_the_barbershop_may_manage_invitations(): void
    {
        $invitation = $this->invite();

        foreach ([$this->collaborator, $this->customer] as $person) {
            Sanctum::actingAs($person, []);
            $this->getJson($this->admin($this->puraNavaja))->assertForbidden();
            $this->postJson($this->admin($this->puraNavaja), $this->payload('otra@correo.cr'))->assertForbidden();
            $this->deleteJson($this->admin($this->puraNavaja)."/{$invitation}")->assertForbidden();
        }

        foreach ([$this->foreignOwner, $this->stranger] as $person) {
            Sanctum::actingAs($person, []);
            $this->getJson($this->admin($this->puraNavaja))->assertNotFound();
            $this->postJson($this->admin($this->puraNavaja), $this->payload('otra@correo.cr'))->assertNotFound();
            $this->deleteJson($this->admin($this->puraNavaja)."/{$invitation}")->assertNotFound();
        }

        $this->assertSame(1, $this->rows('invitations')->count());
    }

    /** The owner invites an email with some services: the answer carries no token, and the email reaches the person with the link of the web client (RF-06). */
    public function test_the_owner_invites_by_email(): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->postJson($this->admin($this->puraNavaja), ['correo' => ' Lucia@Correo.cr ', 'servicios' => [$this->haircut, $this->kidsHaircut]])->assertCreated();

        $id = $response->json('data.id');
        $this->assertTrue(Str::isUuid($id, 7));
        $response->assertExactJson(['data' => [
            'id' => $id,
            'correo' => 'lucia@correo.cr',
            'servicios' => [$this->haircut, $this->kidsHaircut],
            'enviada' => '2030-03-01T08:00:00-06:00',
            'vence' => '2030-03-08T08:00:00-06:00',
        ]]);
        $this->assertStringNotContainsString('token', (string) $response->getContent());

        $token = $this->tokenOfLastMail();
        $mail = Mail::sent(NoticeMail::class)->last();
        $this->assertInstanceOf(NoticeMail::class, $mail);
        $this->assertTrue($mail->hasTo('lucia@correo.cr'));
        $this->assertSame('pura-navaja le invita a unirse a su equipo en BarberHub', $mail->envelope()->subject);
        $this->assertSame('http://localhost:5173/aceptar-invitacion?token='.$token, $mail->notice->actionUrl);
        $mail->assertSeeInHtml('Crear mi contraseña');
        $mail->assertSeeInText('El enlace es válido por 7 días y solo se puede usar una vez.');
        $mail->assertSeeInText('Este mensaje fue enviado por BarberHub en nombre de pura-navaja.');
        $this->assertSame('pura-navaja', $mail->notice->branding->barbershopName);
    }

    /** The email carries the logo of the barbershop when it has one (MT-08). */
    public function test_the_email_carries_the_logo_of_the_barbershop(): void
    {
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['logo_path' => "barbershops/{$this->puraNavaja}/logo/logo.png"]);

        $this->invite();

        $mail = Mail::sent(NoticeMail::class)->last();
        $this->assertInstanceOf(NoticeMail::class, $mail);
        $this->assertNotNull($mail->notice->branding->logoUrl);
        $this->assertStringContainsString("/barbershops/{$this->puraNavaja}/logo/logo.png", (string) $mail->notice->branding->logoUrl);
    }

    /** The invitation does not look at the preferences: it is sent although the invited person, who already has an account, turned the reminders off (NO-10). */
    public function test_the_email_is_sent_whatever_the_preferences_of_an_existing_account(): void
    {
        UserModel::query()->where('id', $this->stranger->id)->update(['reminders_enabled' => false]);

        $this->invite('dani@barberhub.test');

        Mail::assertSent(NoticeMail::class, 1);
    }

    /** Only the hash of the token is stored; the token is in no column (UP-04). */
    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $row = $this->rows('invitations')->first();
        $this->assertNotNull($row);
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, json_encode((array) $row, JSON_THROW_ON_ERROR));
        $this->assertSame(
            (string) MembershipModel::query()->where('user_id', $this->owner->id)->value('id'),
            $row->invited_by_membership_id,
        );
        $this->assertSame([$this->haircut], $this->rows('invitation_services')->pluck('service_id')->all());
    }

    /** The task that waits in the real queue shows neither the email nor a token: the token is born when the task runs (UP-04). */
    public function test_the_queued_task_shows_neither_the_email_nor_a_token(): void
    {
        $this->app->make('config')->set('queue.default', 'database');

        $this->invite('lucia@correo.cr');

        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringContainsString('SendCollaboratorInvitationNoticeJob', $payload);
        $this->assertStringNotContainsString('lucia@correo', $payload);
        $this->assertStringNotContainsString($this->puraNavaja, $payload);
        Mail::assertNothingSent();
        // The row waits with the hash of a token that nobody has: its link cannot be used until the email is written.
        $hash = (string) $this->rows('invitations')->value('token_hash');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    /** The owner sees the pending invitations of the barbershop, the newest first, and nobody else's. */
    public function test_the_owner_lists_the_pending_invitations_of_the_barbershop(): void
    {
        $first = $this->invite('lucia@correo.cr');
        $this->clock->moveTo($this->clock->now()->modify('+1 hour'));
        $second = $this->invite('andres@correo.cr', [$this->haircut, $this->kidsHaircut]);
        $this->inviteAs($this->foreignOwner, $this->fadeLab, 'otro@correo.cr', [$this->foreignService]);

        Sanctum::actingAs($this->owner, []);
        $response = $this->getJson($this->admin($this->puraNavaja))->assertOk();

        $this->assertSame([$second, $first], $response->json('data.*.id'));
        $this->assertSame(['andres@correo.cr', 'lucia@correo.cr'], $response->json('data.*.correo'));
        $this->assertSame([$this->haircut, $this->kidsHaircut], $response->json('data.0.servicios'));
        $this->assertStringNotContainsString('otro@correo.cr', (string) $response->getContent());
    }

    /** An invitation that expired or was accepted is not pending any more, so it is not listed. */
    public function test_expired_and_used_invitations_are_not_listed(): void
    {
        $this->invite('vencida@correo.cr');
        $this->clock->moveTo($this->clock->now()->modify('+8 days'));
        $this->invite('usada@correo.cr');
        $this->accept($this->tokenOfLastMail(), ['nombre' => 'Uso Una', 'contrasena' => self::PASSWORD])->assertNoContent();
        $pending = $this->invite('pendiente@correo.cr');

        Sanctum::actingAs($this->owner, []);
        $this->assertSame([$pending], $this->getJson($this->admin($this->puraNavaja))->assertOk()->json('data.*.id'));
    }

    /** A pending invitation is cancelled: its link stops working at once and it leaves the list (RF-06). */
    public function test_a_pending_invitation_can_be_cancelled(): void
    {
        $id = $this->invite();
        $token = $this->tokenOfLastMail();

        Sanctum::actingAs($this->owner, []);
        $this->deleteJson($this->admin($this->puraNavaja)."/{$id}")->assertNoContent();

        $this->assertSame([], $this->getJson($this->admin($this->puraNavaja))->json('data'));
        $this->consult($token)->assertUnprocessable()->assertJsonPath('errores.token.0', $this->invalidLink());
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertUnprocessable()->assertJsonPath('errores.token.0', $this->invalidLink());
        $this->assertSame(0, $this->rows('invitation_services')->count());
        $this->assertNull(UserModel::query()->where('email', 'lucia@correo.cr')->first());
    }

    /** Cancelling an invitation that is unknown, of another barbershop or already cancelled reads as one that does not exist (UP-06). */
    public function test_cancelling_what_is_not_pending_answers_not_found(): void
    {
        $id = $this->invite();

        Sanctum::actingAs($this->owner, []);
        $this->deleteJson($this->admin($this->puraNavaja).'/'.Str::uuid7())->assertNotFound()->assertJsonPath('type', 'http://localhost/problemas/no-encontrado');
        $this->deleteJson($this->admin($this->puraNavaja)."/{$id}")->assertNoContent();
        $this->deleteJson($this->admin($this->puraNavaja)."/{$id}")->assertNotFound();

        $foreign = $this->inviteAs($this->foreignOwner, $this->fadeLab, 'otro@correo.cr', []);
        Sanctum::actingAs($this->owner, []);
        $this->deleteJson($this->admin($this->puraNavaja)."/{$foreign}")->assertNotFound();
        $this->assertSame(1, $this->rows('invitations', $this->fadeLab)->count());
    }

    /** Inviting an email again replaces the pending invitation: one is pending, and only the last link works (RF-06). */
    public function test_inviting_again_replaces_the_pending_invitation(): void
    {
        $this->invite('lucia@correo.cr', [$this->haircut]);
        $first = $this->tokenOfLastMail();
        $this->invite('LUCIA@correo.cr', [$this->kidsHaircut]);
        $second = $this->tokenOfLastMail();

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $this->rows('invitations')->count());
        $this->accept($first, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertUnprocessable()->assertJsonPath('errores.token.0', $this->invalidLink());
        $this->accept($second, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();

        $collaborator = $this->rows('collaborators')->where('barbershop_id', $this->puraNavaja)->whereIn('user_id', UserModel::query()->where('email', 'lucia@correo.cr')->pluck('id'))->first();
        $this->assertNotNull($collaborator);
        $this->assertSame([$this->kidsHaircut], $this->rows('collaborator_services')->where('collaborator_id', $collaborator->id)->pluck('service_id')->all());
    }

    /** The same email may be invited by two barbershops at once: each invitation is its own. */
    public function test_two_barbershops_may_invite_the_same_email(): void
    {
        $this->invite('lucia@correo.cr');
        $mine = $this->tokenOfLastMail();
        $this->inviteAs($this->foreignOwner, $this->fadeLab, 'lucia@correo.cr', [$this->foreignService]);

        $this->assertSame(1, $this->rows('invitations')->count());
        $this->assertSame(1, $this->rows('invitations', $this->fadeLab)->count());
        $this->accept($mine, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();

        $this->assertSame(0, $this->rows('invitations')->whereNull('used_at')->count());
        $this->assertSame(1, $this->rows('invitations', $this->fadeLab)->whereNull('used_at')->count());
    }

    /** A service that the barbershop does not have is refused with its position, whether it does not exist or belongs to another barbershop (CO-01). */
    public function test_an_unknown_service_is_refused(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->admin($this->puraNavaja), ['correo' => 'lucia@correo.cr', 'servicios' => [$this->haircut, (string) Str::uuid7()]])
            ->assertUnprocessable()
            ->assertJsonPath('errores', ['servicios.1' => ['El servicio no existe en la barbería.']]);
        $this->postJson($this->admin($this->puraNavaja), ['correo' => 'lucia@correo.cr', 'servicios' => [$this->foreignService]])
            ->assertUnprocessable()
            ->assertJsonPath('errores', ['servicios.0' => ['El servicio no existe en la barbería.']]);

        $this->assertSame(0, $this->rows('invitations')->count());
        Mail::assertNothingSent();
    }

    /** The email must be valid and the list of services must be present. */
    public function test_the_request_is_validated(): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->admin($this->puraNavaja), ['servicios' => []])->assertUnprocessable()->assertJsonValidationErrors(['correo'], 'errores');
        $this->postJson($this->admin($this->puraNavaja), ['correo' => 'no-es-un-correo', 'servicios' => []])->assertUnprocessable()->assertJsonValidationErrors(['correo'], 'errores');
        $this->postJson($this->admin($this->puraNavaja), ['correo' => 'lucia@correo.cr'])->assertUnprocessable()->assertJsonValidationErrors(['servicios'], 'errores');
        $this->postJson($this->admin($this->puraNavaja), ['correo' => 'lucia@correo.cr', 'servicios' => ['no-uuid']])->assertUnprocessable()->assertJsonValidationErrors(['servicios.0'], 'errores');
    }

    /** The link works up to the last second of the 7 days and stops working at the instant they end (UP-04). */
    public function test_the_link_expires_after_seven_days(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $this->clock->moveTo($this->clock->now()->modify('+7 days'));
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos')
            ->assertJsonPath('errores.token.0', $this->invalidLink());
        $this->assertNull(UserModel::query()->where('email', 'lucia@correo.cr')->first());
    }

    /** One second before the end the link still works. */
    public function test_the_link_works_until_the_last_second(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $this->clock->moveTo($this->clock->now()->modify('+6 days 23 hours 59 minutes 59 seconds'));
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
    }

    /** The link works once: the second use is refused like an unknown one, and nothing is created twice (UP-04). */
    public function test_the_link_cannot_be_used_twice(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
        $this->accept($token, ['nombre' => 'Otra Persona', 'contrasena' => 'Otra#Clave88'])->assertUnprocessable()->assertJsonPath('errores.token.0', $this->invalidLink());
        $this->consult($token)->assertUnprocessable();

        $this->assertSame(1, UserModel::query()->where('email', 'lucia@correo.cr')->count());
        $this->assertSame(1, $this->rows('invitations')->whereNotNull('used_at')->count());
        $this->login('lucia@correo.cr', self::PASSWORD)->assertCreated();
        $this->login('lucia@correo.cr', 'Otra#Clave88')->assertUnauthorized();
    }

    /** An unknown, a made-up and a malformed token get the same answer (UP-04). */
    public function test_an_unknown_token_is_refused_without_saying_why(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        foreach ([str_repeat('a', 64), 'b'.substr($token, 1), 'corto'] as $wrong) {
            $this->consult($wrong)->assertUnprocessable()->assertJsonPath('errores.token.0', $this->invalidLink());
            $this->accept($wrong, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertUnprocessable()->assertJsonPath('errores.token.0', $this->invalidLink());
        }

        $this->postJson(self::CONSULT, [])->assertUnprocessable()->assertJsonValidationErrors(['token'], 'errores');
        $this->postJson(self::ACCEPT, [])->assertUnprocessable()->assertJsonValidationErrors(['token'], 'errores');
    }

    /** A new account: the person chooses their name and password, the account is active with that password, and they are a collaborator of the barbershop with the invited services (RF-06, UP-04). */
    public function test_accepting_creates_the_account_the_role_and_the_profile(): void
    {
        $this->invite('lucia@correo.cr', [$this->haircut, $this->kidsHaircut]);
        $token = $this->tokenOfLastMail();

        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();

        $account = UserModel::query()->where('email', 'lucia@correo.cr')->firstOrFail();
        $this->assertSame('Lucía Mora', $account->name);
        $this->assertTrue($account->is_active);
        $this->assertFalse($account->must_change_password);
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password));
        $this->assertDatabaseMissing('users', ['password' => self::PASSWORD]);

        $membership = MembershipModel::query()->where('user_id', $account->id)->sole();
        $this->assertSame($this->puraNavaja, $membership->barbershop_id);
        $this->assertSame('collaborator', $membership->role);
        $this->assertTrue($membership->is_active);

        $profile = $this->rows('collaborators')->where('user_id', $account->id)->sole();
        $this->assertSame($this->puraNavaja, $profile->barbershop_id);
        $this->assertTrue($profile->is_active);
        $this->assertEqualsCanonicalizing([$this->haircut, $this->kidsHaircut], $this->rows('collaborator_services')->where('collaborator_id', $profile->id)->pluck('service_id')->all());

        $this->login('lucia@correo.cr', self::PASSWORD)->assertCreated();
    }

    /** The invited collaborator reaches their own agenda and nothing of the administration, and nothing of another barbershop (RF-06, UP-02, CO-04). */
    public function test_the_invited_collaborator_only_reaches_their_agenda_and_their_barbershop(): void
    {
        $this->invite();
        $this->accept($this->tokenOfLastMail(), ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
        $account = UserModel::query()->where('email', 'lucia@correo.cr')->firstOrFail();

        Sanctum::actingAs($account, []);
        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/reservas?desde=2030-03-01&hasta=2030-03-02")->assertOk();
        $this->getJson($this->admin($this->puraNavaja))->assertForbidden();
        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/administracion/colaboradores")->assertForbidden();
        $this->getJson("/api/v1/barberias/{$this->fadeLab}/reservas?desde=2030-03-01&hasta=2030-03-02")->assertNotFound();
        $this->getJson($this->admin($this->fadeLab))->assertNotFound();
    }

    /** An email that already has an account joins as a collaborator without changing its password or its name (PL-02, UP-04). */
    public function test_an_existing_account_gets_the_role_and_keeps_its_password(): void
    {
        UserModel::query()->where('id', $this->stranger->id)->update(['password' => Hash::make(self::PASSWORD), 'name' => 'Daniela Solís']);
        $this->invite('dani@barberhub.test');
        $token = $this->tokenOfLastMail();

        $this->consult($token)->assertOk()->assertExactJson(['data' => ['barberia' => 'pura-navaja', 'correo' => 'dani@barberhub.test', 'cuenta_existente' => true]]);
        $this->accept($token, ['nombre' => 'Otro Nombre', 'contrasena' => 'Otra#Clave88'])->assertNoContent();

        $account = UserModel::query()->findOrFail($this->stranger->id);
        $this->assertSame('Daniela Solís', $account->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $account->password));
        $this->assertFalse(Hash::check('Otra#Clave88', $account->password));
        $this->assertSame(1, UserModel::query()->where('email', 'dani@barberhub.test')->count());
        $this->assertSame('collaborator', MembershipModel::query()->where('user_id', $account->id)->where('barbershop_id', $this->puraNavaja)->sole()->role);
        $this->assertSame(1, $this->rows('collaborators')->where('user_id', $account->id)->count());
        $this->login('dani@barberhub.test', self::PASSWORD)->assertCreated();
        $this->login('dani@barberhub.test', 'Otra#Clave88')->assertUnauthorized();
    }

    /** An account that is a collaborator, an owner or a customer of another barbershop keeps that role there and joins this one (UP-07). */
    public function test_a_role_in_another_barbershop_does_not_get_in_the_way(): void
    {
        $this->invite('luis@barberhub.test');

        $this->accept($this->tokenOfLastMail(), [])->assertNoContent();

        $roles = MembershipModel::query()->where('user_id', $this->foreignOwner->id)->orderBy('role')->pluck('role', 'barbershop_id')->all();
        $this->assertSame([$this->puraNavaja => 'collaborator', $this->fadeLab => 'owner'], $roles);
    }

    /** A person who already has a role in the barbershop is refused with a stable error, and the link is not spent (UP-07). */
    public function test_a_person_with_a_role_in_the_barbershop_is_refused(): void
    {
        foreach (['ana@barberhub.test', 'beto@barberhub.test', 'cata@barberhub.test'] as $email) {
            $this->invite($email);
            $token = $this->tokenOfLastMail();

            $this->accept($token, [])
                ->assertStatus(409)
                ->assertJsonPath('type', 'http://localhost/problemas/conflicto')
                ->assertJsonPath('detail', 'La persona ya tiene un rol en esta barbería.');

            $this->assertSame(1, $this->rows('invitations')->where('email', $email)->whereNull('used_at')->count(), "{$email}: the link was spent");
            $this->assertSame(1, MembershipModel::query()->whereIn('user_id', UserModel::query()->where('email', $email)->pluck('id'))->where('barbershop_id', $this->puraNavaja)->count());
        }

        $this->assertSame('owner', MembershipModel::query()->where('user_id', $this->owner->id)->sole()->role);
        $this->assertSame('customer', MembershipModel::query()->where('user_id', $this->customer->id)->sole()->role);
        $this->assertSame(0, $this->rows('collaborators')->count());
    }

    /** A password that misses requirements is refused with the whole list and the link is not spent (SE-07). */
    public function test_a_weak_password_is_refused_and_the_link_survives(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $response = $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => 'debil'])->assertUnprocessable();

        $this->assertCount(4, $response->json('errores.contrasena'));
        $this->assertNull(UserModel::query()->where('email', 'lucia@correo.cr')->first());
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
    }

    /** A new account needs a name and a password: each missing one is reported under its field and the link survives. */
    public function test_a_new_account_needs_a_name_and_a_password(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $this->accept($token, ['contrasena' => self::PASSWORD])->assertUnprocessable()->assertJsonPath('errores.nombre.0', 'Escriba su nombre para crear su cuenta.');
        $this->accept($token, ['nombre' => 'Lucía Mora'])->assertUnprocessable()->assertJsonPath('errores.contrasena.0', 'Elija una contraseña para crear su cuenta.');
        $this->accept($token, ['nombre' => str_repeat('a', 121), 'contrasena' => self::PASSWORD])->assertUnprocessable()->assertJsonValidationErrors(['nombre'], 'errores');

        $this->assertNull(UserModel::query()->where('email', 'lucia@correo.cr')->first());
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
    }

    /** The consult tells what the link invites to without spending it, and an email with no account is asked for a name and a password. */
    public function test_the_consult_does_not_spend_the_link(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $this->consult($token)->assertOk()->assertExactJson(['data' => ['barberia' => 'pura-navaja', 'correo' => 'lucia@correo.cr', 'cuenta_existente' => false]]);
        $this->consult($token)->assertOk();

        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
    }

    /** The barbershop comes from the link, never from what the sender writes: a body that names another barbershop changes nothing (MT-03, RD-18). */
    public function test_the_barbershop_comes_from_the_link_and_not_from_the_body(): void
    {
        $this->invite();
        $token = $this->tokenOfLastMail();

        $this->postJson(self::ACCEPT, ['token' => $token, 'nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD, 'barberia' => $this->fadeLab, 'barbershop_id' => $this->fadeLab], ['X-Barbershop' => $this->fadeLab])->assertNoContent();

        $account = UserModel::query()->where('email', 'lucia@correo.cr')->firstOrFail();
        $this->assertSame([$this->puraNavaja], MembershipModel::query()->where('user_id', $account->id)->pluck('barbershop_id')->all());
        $this->assertSame(0, $this->rows('collaborators', $this->fadeLab)->count());
        $this->assertSame(1, $this->rows('collaborators')->count());
    }

    /** The invitations of two barbershops to the same person stay apart: each link joins only its own barbershop, with its own services. */
    public function test_each_link_joins_only_its_own_barbershop(): void
    {
        $this->invite('lucia@correo.cr', [$this->haircut]);
        $mine = $this->tokenOfLastMail();
        $this->inviteAs($this->foreignOwner, $this->fadeLab, 'lucia@correo.cr', [$this->foreignService]);
        $theirs = $this->tokenOfLastMail();

        $this->accept($theirs, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
        $account = UserModel::query()->where('email', 'lucia@correo.cr')->firstOrFail();
        $this->assertSame([$this->fadeLab], MembershipModel::query()->where('user_id', $account->id)->pluck('barbershop_id')->all());
        $this->assertSame([$this->foreignService], $this->rows('collaborator_services', $this->fadeLab)->pluck('service_id')->all());
        $this->assertSame(0, $this->rows('collaborators')->count());

        $this->accept($mine, [])->assertNoContent();
        $this->assertEqualsCanonicalizing([$this->puraNavaja, $this->fadeLab], MembershipModel::query()->where('user_id', $account->id)->pluck('barbershop_id')->all());
        $this->assertSame(1, $this->rows('collaborators')->where('user_id', $account->id)->count());
        $this->assertSame(1, $this->rows('collaborators', $this->fadeLab)->where('user_id', $account->id)->count());
    }

    /** The database function that finds the barbershop of a link answers only its identifier, finds nothing for an unknown hash and leaves the barbershop of the connection as it found it. */
    public function test_the_locator_finds_the_barbershop_of_a_link_and_restores_the_connection(): void
    {
        $this->invite();
        $hash = hash('sha256', $this->tokenOfLastMail());

        foreach ([null, $this->puraNavaja, $this->fadeLab] as $scope) {
            $this->scopeQueriesTo($scope);

            $this->assertSame($this->puraNavaja, DB::scalar('select locate_invitation_of_link(?)', [$hash]));
            $this->assertNull(DB::scalar('select locate_invitation_of_link(?)', [hash('sha256', 'desconocido')]));
            $this->assertSame($scope ?? '', DB::scalar("select current_setting('app.barbershop_id', true)"), 'the barbershop of the connection changed');
        }
    }

    /** Neither the token, nor the password, nor the email reach the log, in a success or in the refusals (SE-01, SE-02). */
    public function test_nothing_secret_reaches_the_log(): void
    {
        $this->invite('lucia@correo.cr');
        $token = $this->tokenOfLastMail();
        $this->accept('b'.substr($token, 1), ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertUnprocessable();
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => 'debil'])->assertUnprocessable();
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertNoContent();
        $this->accept($token, ['nombre' => 'Lucía Mora', 'contrasena' => self::PASSWORD])->assertUnprocessable();

        foreach ([$token, self::PASSWORD, 'debil', 'lucia@correo.cr'] as $secret) {
            foreach ($this->logged as $line) {
                $this->assertStringNotContainsString($secret, $line);
            }
        }
    }

    /**
     * Invite as the owner of Pura Navaja and answer the identifier of the invitation.
     *
     * @param  list<string>|null  $services
     */
    private function invite(string $email = 'lucia@correo.cr', ?array $services = null): string
    {
        return $this->inviteAs($this->owner, $this->puraNavaja, $email, $services ?? [$this->haircut]);
    }

    /**
     * @param  list<string>  $services
     */
    private function inviteAs(UserModel $owner, string $barbershopId, string $email, array $services): string
    {
        Auth::forgetGuards();
        Sanctum::actingAs($owner, []);

        return (string) $this->postJson($this->admin($barbershopId), ['correo' => $email, 'servicios' => $services])->assertCreated()->json('data.id');
    }

    /** The token that went in the last email. */
    private function tokenOfLastMail(): string
    {
        $mail = Mail::sent(NoticeMail::class)->last();
        $this->assertInstanceOf(NoticeMail::class, $mail);
        parse_str((string) parse_url((string) $mail->notice->actionUrl, PHP_URL_QUERY), $query);

        $token = $query['token'] ?? null;
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function accept(string $token, array $body): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson(self::ACCEPT, ['token' => $token, ...$body]);
    }

    /** @return TestResponse<JsonResponse> */
    private function consult(string $token): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson(self::CONSULT, ['token' => $token]);
    }

    /** @return TestResponse<JsonResponse> */
    private function login(string $email, string $password): TestResponse
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/tokens', ['correo' => $email, 'contrasena' => $password, 'dispositivo' => 'Celular']);
    }

    /** @return array<string, mixed> */
    private function payload(string $email = 'lucia@correo.cr'): array
    {
        return ['correo' => $email, 'servicios' => [$this->haircut]];
    }

    private function admin(string $barbershopId): string
    {
        return sprintf(self::ADMIN, $barbershopId);
    }

    private function invalidLink(): string
    {
        return 'El enlace de la invitación no es válido o ya venció. Pida a la barbería que le envíe otra.';
    }

    /** A query on a table of a barbershop, which only shows that barbershop's rows (MT-06); Pura Navaja unless another is given. */
    private function rows(string $table, ?string $barbershopId = null): Builder
    {
        $this->scopeQueriesTo($barbershopId ?? $this->puraNavaja);

        return DB::table($table);
    }

    private function createBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Create a person, with a role in a barbershop unless none is given. */
    private function createMember(string $email, ?string $barbershopId, ?string $role): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => Hash::make(self::PASSWORD)]);

        if ($barbershopId !== null) {
            MembershipModel::query()->create(['user_id' => $user->id, 'barbershop_id' => $barbershopId, 'role' => $role, 'is_active' => true]);
        }

        return $user;
    }

    /** Insert a service of a barbershop straight into the table and return its identifier. */
    private function createService(string $barbershopId, string $name): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => $name, 'duration_minutes' => 30, 'price' => 7000]);
        $this->scopeQueriesTo(null);

        return $id;
    }
}
