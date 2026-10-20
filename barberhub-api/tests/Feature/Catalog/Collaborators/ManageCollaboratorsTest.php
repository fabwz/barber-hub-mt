<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Collaborators;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Catalog\Collaborators\Application\UseCases\ListCollaboratorsOfferingService;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Infrastructure\Persistence\Repositories\EloquentCollaboratorRepository;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\Catalog\FailingCollaboratorRepository;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The owner creates collaborators with their services, weekly schedule and breaks (RF-05, CO-01 to CO-03, HO-02, UP-04, UP-05, SE-07). */
final class ManageCollaboratorsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private const string TEMPORARY_PASSWORD = 'Temporal#2026';

    private string $puraNavaja;

    private string $fadeLab;

    private UserModel $owner;

    private UserModel $collaborator;

    private UserModel $customer;

    private UserModel $stranger;

    private string $haircut;

    private string $kidsHaircut;

    /** Pura Navaja opens Monday from 09:00 to 12:00 and from 13:00 to 18:00 and has two services, an owner, a collaborator and a customer; Fade Lab is another barbershop. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->createBarbershop('pura-navaja');
        $this->fadeLab = $this->createBarbershop('fade-lab');
        $this->owner = $this->createMember('ana@barberhub.test', $this->puraNavaja, 'owner');
        $this->collaborator = $this->createMember('beto@barberhub.test', $this->puraNavaja, 'collaborator');
        $this->customer = $this->createMember('cata@barberhub.test', $this->puraNavaja, 'customer');
        $this->createMember('luis@barberhub.test', $this->fadeLab, 'owner');
        $this->stranger = $this->createMember('dani@barberhub.test', null, null);

        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('opening_hours')->insert([
            ['barbershop_id' => $this->puraNavaja, 'day_of_week' => 1, 'opens_at' => '09:00', 'closes_at' => '12:00'],
            ['barbershop_id' => $this->puraNavaja, 'day_of_week' => 1, 'opens_at' => '13:00', 'closes_at' => '18:00'],
        ]);
        $this->haircut = $this->createService($this->puraNavaja, 'Corte clásico');
        $this->kidsHaircut = $this->createService($this->puraNavaja, 'Corte infantil');
    }

    /** Without a session every operation answers the unauthenticated problem. */
    public function test_administration_requires_a_session(): void
    {
        $id = (string) Str::uuid7();

        $this->getJson($this->url($this->puraNavaja))->assertUnauthorized();
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertUnauthorized();
        $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))->assertUnauthorized();
        $this->getJson($this->url($this->puraNavaja, $id))->assertUnauthorized();
        $this->patchJson($this->url($this->puraNavaja, $id), ['activo' => false])->assertUnauthorized();
        $this->putJson($this->url($this->puraNavaja, "{$id}/servicios"), ['servicios' => []])->assertUnauthorized();
        $this->putJson($this->url($this->puraNavaja, "{$id}/horario"), ['turnos' => [], 'pausas' => []])->assertUnauthorized();
    }

    /** The owner creates the account, the role and the profile in one request; the response never carries the password (RF-05, UP-04). */
    public function test_owner_creates_a_collaborator_with_an_account(): void
    {
        Sanctum::actingAs($this->owner, []);

        $response = $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        $id = $response->json('data.id');
        $response->assertExactJson(['data' => [
            'id' => $id,
            'nombre' => 'Kevin Solano',
            'correo' => 'kevin@barberhub.test',
            'activo' => true,
            'servicios' => [],
            'turnos' => [],
            'pausas' => [],
        ]]);
        $this->assertTrue(Str::isUuid($id, 7));
        $this->assertStringNotContainsString(self::TEMPORARY_PASSWORD, $response->getContent() ?: '');
        $this->assertStringNotContainsString('contrasena', $response->getContent() ?: '');

        $user = UserModel::query()->where('email', 'kevin@barberhub.test')->sole();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check(self::TEMPORARY_PASSWORD, $user->password));
        $this->assertNotSame(self::TEMPORARY_PASSWORD, $user->password);

        $membership = MembershipModel::query()->where('user_id', $user->id)->sole();
        $this->assertSame([$this->puraNavaja, 'collaborator', true], [$membership->barbershop_id, $membership->role, $membership->is_active]);

        $this->scopeQueriesTo($this->puraNavaja);
        $profile = DB::table('collaborators')->sole();
        $this->assertSame([$id, $this->puraNavaja, $user->id, true], [$profile->id, $profile->barbershop_id, $profile->user_id, $profile->is_active]);
    }

    /** The temporary password is not written to the log, whether the request works or is refused (SE-07). */
    public function test_the_temporary_password_is_never_logged(): void
    {
        $handler = new TestHandler;
        $logger = Log::driver();
        $this->assertInstanceOf(Logger::class, $logger);
        $monolog = $logger->getLogger();
        $this->assertInstanceOf(MonologLogger::class, $monolog);
        $monolog->pushHandler($handler);
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertUnprocessable();
        $this->postJson($this->url($this->puraNavaja), $this->payload(email: 'otro@barberhub.test', password: 'Sin-numero'))->assertUnprocessable();

        $this->app->bind(CollaboratorRepository::class, fn () => new FailingCollaboratorRepository(new EloquentCollaboratorRepository($this->app->make(BarbershopContext::class))));
        $this->postJson($this->url($this->puraNavaja), $this->payload(email: 'caido@barberhub.test'))->assertStatus(500);

        $logged = implode("\n", array_map(fn ($record): string => $record->message.' '.json_encode($record->context), $handler->getRecords()));
        $this->assertNotSame([], $handler->getRecords(), 'The failure of the last request should have been logged, otherwise this test proves nothing.');
        $this->assertStringNotContainsString(self::TEMPORARY_PASSWORD, $logged);
        $this->assertStringNotContainsString('Sin-numero', $logged);
    }

    /** A temporary password that breaks SE-07 is refused naming the field and nothing is created. */
    #[DataProvider('weakPasswords')]
    public function test_a_temporary_password_that_breaks_the_policy_is_refused(string $password, string $detail): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $this->payload(password: $password))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonPath('errores.contrasena_temporal.0', $detail);

        $this->assertNothingWasCreated();
    }

    /**
     * Passwords that break one requirement of SE-07, with the message that names it.
     *
     * @return array<string, array{string, string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Ab1!', 'La contraseña debe tener al menos 8 caracteres.'],
            'without uppercase' => ['temporal#1', 'La contraseña debe incluir al menos una letra mayúscula.'],
            'without lowercase' => ['TEMPORAL#1', 'La contraseña debe incluir al menos una letra minúscula.'],
            'without number' => ['Temporal#!', 'La contraseña debe incluir al menos un número.'],
            'without symbol' => ['Temporal12', 'La contraseña debe incluir al menos un signo especial.'],
        ];
    }

    /**
     * Bodies the request refuses, with the fields the error must name.
     *
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidBodies(): array
    {
        return [
            'empty body' => [[], ['nombre', 'correo', 'contrasena_temporal']],
            'malformed email' => [['nombre' => 'Kevin', 'correo' => 'kevin.barberhub', 'contrasena_temporal' => 'Temporal#1'], ['correo']],
            'blank name' => [['nombre' => '', 'correo' => 'kevin@barberhub.test', 'contrasena_temporal' => 'Temporal#1'], ['nombre']],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $fields
     */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_is_refused_naming_the_fields(array $body, array $fields): void
    {
        Sanctum::actingAs($this->owner, []);

        $this->postJson($this->url($this->puraNavaja), $body)->assertUnprocessable()->assertJsonValidationErrors($fields, 'errores');

        $this->assertNothingWasCreated();
    }

    /** An email that already has an account answers the existing repeated email error (422) and leaves the first account alone (RF-06 links existing accounts). */
    public function test_a_repeated_email_is_refused(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertCreated();

        foreach (['kevin@barberhub.test', ' KEVIN@barberhub.test ', 'ana@barberhub.test'] as $email) {
            $this->postJson($this->url($this->puraNavaja), $this->payload(email: $email))
                ->assertUnprocessable()
                ->assertJsonPath('type', $this->problem('datos-invalidos'))
                ->assertJsonPath('detail', 'Ya existe una cuenta con ese correo.');
        }

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(1, DB::table('collaborators')->count());
        $this->assertSame(1, UserModel::query()->where('email', 'kevin@barberhub.test')->count());
    }

    /** If the profile cannot be saved, the account and the role created a moment before do not stay (atomic creation). */
    public function test_a_failure_while_saving_the_profile_leaves_no_account_and_no_role(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->app->bind(CollaboratorRepository::class, fn () => new FailingCollaboratorRepository(new EloquentCollaboratorRepository($this->app->make(BarbershopContext::class))));

        $this->postJson($this->url($this->puraNavaja), $this->payload())->assertStatus(500);

        $this->assertSame(0, UserModel::query()->where('email', 'kevin@barberhub.test')->count());
        $this->assertSame(3, MembershipModel::query()->where('barbershop_id', $this->puraNavaja)->count());
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('collaborators')->count());
    }

    /** The owner creates their own profile, keeps the owner role and gets no second membership (CO-02, UP-07). */
    public function test_owner_creates_their_own_profile_and_keeps_the_owner_role(): void
    {
        Sanctum::actingAs($this->owner, []);
        $memberships = MembershipModel::query()->count();

        $response = $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))->assertCreated();

        $response->assertJsonPath('data.nombre', 'ana@barberhub.test')->assertJsonPath('data.activo', true);
        $this->assertSame($memberships, MembershipModel::query()->count());
        $this->assertSame('owner', MembershipModel::query()->where('user_id', $this->owner->id)->where('barbershop_id', $this->puraNavaja)->sole()->role);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame($this->owner->id, DB::table('collaborators')->sole()->user_id);
    }

    /** The owner has one profile: asking again answers 409 and changes nothing. */
    public function test_owner_cannot_create_a_second_profile(): void
    {
        Sanctum::actingAs($this->owner, []);
        $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))->assertCreated();

        $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))
            ->assertConflict()
            ->assertJsonPath('type', $this->problem('conflicto'))
            ->assertJsonPath('detail', 'La persona ya tiene un perfil de colaborador en esta barbería.');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(1, DB::table('collaborators')->count());
    }

    /** A collaborator cannot create a profile for themselves: only the owner can (UP-03). */
    public function test_a_common_collaborator_cannot_create_their_own_profile(): void
    {
        Sanctum::actingAs($this->collaborator, []);

        $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))
            ->assertForbidden()
            ->assertJsonPath('type', $this->problem('prohibido'));

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('collaborators')->count());
    }

    /** The owner lists every collaborator, oldest first, and reads one; collaborators of another barbershop never show. */
    public function test_owner_lists_and_reads_the_collaborators(): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->postJson($this->url($this->puraNavaja), $this->payload())->json('data.id');
        $ana = $this->postJson($this->url($this->puraNavaja), $this->payload(name: 'Ana Mora', email: 'ana.mora@barberhub.test'))->json('data.id');
        $this->patchJson($this->url($this->puraNavaja, $ana), ['activo' => false])->assertOk();
        $other = $this->createCollaboratorRow($this->fadeLab, 'otro@barberhub.test');

        $this->getJson($this->url($this->puraNavaja))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $kevin)
            ->assertJsonPath('data.1.id', $ana)
            ->assertJsonPath('data.1.activo', false);
        $this->getJson($this->url($this->puraNavaja, $kevin))->assertOk()->assertJsonPath('data.correo', 'kevin@barberhub.test');
        $this->getJson($this->url($this->puraNavaja, $other))->assertNotFound()->assertJsonPath('detail', 'Este colaborador no existe');
        $this->getJson($this->url($this->puraNavaja, (string) Str::uuid7()))->assertNotFound();
        $this->getJson($this->url($this->puraNavaja, 'no-es-un-uuid'))->assertNotFound();
    }

    /** The services are replaced as a whole, and a service of another barbershop is refused naming its position (CO-01, MT-04). */
    public function test_owner_changes_the_services_a_collaborator_offers(): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->createCollaboratorThroughTheApi();
        $foreign = $this->createService($this->fadeLab, 'Corte de otra barbería');

        $this->putJson($this->url($this->puraNavaja, "{$kevin}/servicios"), ['servicios' => [$this->haircut, $this->haircut]])
            ->assertOk()
            ->assertJsonPath('data.servicios', [$this->haircut]);
        $this->putJson($this->url($this->puraNavaja, "{$kevin}/servicios"), ['servicios' => [$this->haircut, $foreign]])
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonPath('errores', ['servicios.1' => ['El servicio no existe en esta barbería.']]);
        $this->putJson($this->url($this->puraNavaja, "{$kevin}/servicios"), ['servicios' => ['no-es-un-uuid']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['servicios.0'], 'errores');
        $this->putJson($this->url($this->puraNavaja, "{$kevin}/servicios"), [])->assertUnprocessable()->assertJsonValidationErrors(['servicios'], 'errores');

        $this->getJson($this->url($this->puraNavaja, $kevin))->assertJsonPath('data.servicios', [$this->haircut]);

        $this->putJson($this->url($this->puraNavaja, "{$kevin}/servicios"), ['servicios' => []])->assertOk()->assertJsonPath('data.servicios', []);
    }

    /** Kevin, without the kids haircut enabled, does not show up for it; whoever has it enabled does (RF-05, CO-01). */
    public function test_kevin_without_the_kids_haircut_is_not_offered_for_it(): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->createCollaboratorThroughTheApi();
        $ana = $this->createCollaboratorThroughTheApi('Ana Mora', 'ana.mora@barberhub.test');
        $this->putJson($this->url($this->puraNavaja, "{$kevin}/servicios"), ['servicios' => [$this->haircut]])->assertOk();
        $this->putJson($this->url($this->puraNavaja, "{$ana}/servicios"), ['servicios' => [$this->haircut, $this->kidsHaircut]])->assertOk();

        $this->scopeQueriesTo($this->puraNavaja);
        $this->app->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($this->puraNavaja)));
        $offering = $this->app->make(ListCollaboratorsOfferingService::class);

        $this->assertSame([$ana], array_map(fn ($collaborator): string => $collaborator->id->value, $offering->execute($this->kidsHaircut)));
        $this->assertSame([$kevin, $ana], array_map(fn ($collaborator): string => $collaborator->id->value, $offering->execute($this->haircut)));
    }

    /** A schedule inside the opening hours is saved with its breaks and read back (RF-05). */
    public function test_owner_defines_the_weekly_schedule_with_breaks(): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->createCollaboratorThroughTheApi();
        $body = [
            'turnos' => [['dia' => 1, 'inicia' => '09:00', 'termina' => '12:00'], ['dia' => 1, 'inicia' => '13:00', 'termina' => '17:30']],
            'pausas' => [['dia' => 1, 'inicia' => '15:00', 'termina' => '15:30']],
        ];

        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), $body)->assertOk()->assertJsonPath('data.turnos', $body['turnos'])->assertJsonPath('data.pausas', $body['pausas']);

        $this->getJson($this->url($this->puraNavaja, $kevin))->assertJsonPath('data.turnos', $body['turnos'])->assertJsonPath('data.pausas', $body['pausas']);

        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), ['turnos' => [['dia' => 1, 'inicia' => '10:00', 'termina' => '11:00']], 'pausas' => []])
            ->assertOk()
            ->assertJsonCount(1, 'data.turnos')
            ->assertJsonCount(0, 'data.pausas');
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(1, DB::table('collaborator_shifts')->count());
        $this->assertSame(0, DB::table('collaborator_breaks')->count());
    }

    /** A shift that starts before the opening, on a closed day or across the gap between two openings is refused naming the shift (HO-02). */
    #[DataProvider('shiftsOutsideTheOpeningHours')]
    public function test_a_shift_outside_the_opening_hours_is_refused(int $day, string $starts, string $ends): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->createCollaboratorThroughTheApi();
        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), ['turnos' => [['dia' => 1, 'inicia' => '09:00', 'termina' => '10:00']], 'pausas' => []])->assertOk();

        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), [
            'turnos' => [['dia' => 1, 'inicia' => '10:00', 'termina' => '11:00'], ['dia' => $day, 'inicia' => $starts, 'termina' => $ends]],
            'pausas' => [],
        ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', $this->problem('datos-invalidos'))
            ->assertJsonPath('errores', ['turnos.1' => ['El turno debe caer dentro del horario de la barbería de ese día.']]);

        $this->getJson($this->url($this->puraNavaja, $kevin))->assertJsonPath('data.turnos', [['dia' => 1, 'inicia' => '09:00', 'termina' => '10:00']]);
    }

    /**
     * Shifts that leave the opening hours of Monday (09:00-12:00 and 13:00-18:00).
     *
     * @return array<string, array{int, string, string}>
     */
    public static function shiftsOutsideTheOpeningHours(): array
    {
        return [
            'starts before the opening' => [1, '08:30', '11:00'],
            'ends after the closing' => [1, '17:00', '18:30'],
            'spans the gap between openings' => [1, '11:00', '14:00'],
            'day the barbershop is closed' => [2, '09:00', '10:00'],
        ];
    }

    /** A break outside its shift and overlapping entries are refused naming the entry (HO-02). */
    public function test_a_break_outside_the_shift_and_overlaps_are_refused(): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->createCollaboratorThroughTheApi();
        $shift = ['dia' => 1, 'inicia' => '09:00', 'termina' => '12:00'];

        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), ['turnos' => [$shift], 'pausas' => [['dia' => 1, 'inicia' => '11:30', 'termina' => '12:30']]])
            ->assertUnprocessable()
            ->assertJsonPath('errores', ['pausas.0' => ['La pausa debe caer dentro de un turno del mismo día.']]);
        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), ['turnos' => [$shift, ['dia' => 1, 'inicia' => '11:00', 'termina' => '12:00']], 'pausas' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errores', ['turnos.1' => ['El turno se traslapa con otro del mismo día.']]);
        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), ['turnos' => [$shift], 'pausas' => [['dia' => 1, 'inicia' => '10:00', 'termina' => '10:30'], ['dia' => 1, 'inicia' => '10:15', 'termina' => '11:00']]])
            ->assertUnprocessable()
            ->assertJsonPath('errores', ['pausas.1' => ['La pausa se traslapa con otra del mismo día.']]);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('collaborator_shifts')->count());
    }

    /**
     * Schedules the request refuses, with the fields the error must name.
     *
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidSchedules(): array
    {
        $valid = ['dia' => 1, 'inicia' => '09:00', 'termina' => '10:00'];

        return [
            'empty body' => [[], ['turnos', 'pausas']],
            'day 8' => [['turnos' => [['dia' => 8] + $valid], 'pausas' => []], ['turnos.0.dia']],
            'hour 24' => [['turnos' => [['inicia' => '24:00'] + $valid], 'pausas' => []], ['turnos.0.inicia']],
            'end equal to the start' => [['turnos' => [['termina' => '09:00'] + $valid], 'pausas' => []], ['turnos.0.termina']],
            'break ending before it starts' => [['turnos' => [$valid], 'pausas' => [['inicia' => '09:40', 'termina' => '09:20'] + $valid]], ['pausas.0.termina']],
            'missing end' => [['turnos' => [['dia' => 1, 'inicia' => '09:00']], 'pausas' => []], ['turnos.0.termina']],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  list<string>  $fields
     */
    #[DataProvider('invalidSchedules')]
    public function test_an_invalid_schedule_is_refused_naming_the_fields(array $body, array $fields): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->createCollaboratorThroughTheApi();

        $this->putJson($this->url($this->puraNavaja, "{$kevin}/horario"), $body)->assertUnprocessable()->assertJsonValidationErrors($fields, 'errores');
    }

    /** Deactivating a collaborator switches off their role and reactivating brings it back (CO-03, UP-05). */
    public function test_deactivating_switches_off_the_role_and_activating_restores_it(): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevinId = $this->createCollaboratorThroughTheApi();
        $kevin = UserModel::query()->where('email', 'kevin@barberhub.test')->sole();

        $this->patchJson($this->url($this->puraNavaja, $kevinId), ['activo' => false])->assertOk()->assertJsonPath('data.activo', false);

        $this->assertFalse(MembershipModel::query()->where('user_id', $kevin->id)->sole()->is_active);
        Sanctum::actingAs($kevin, []);
        $this->getJson($this->url($this->puraNavaja))->assertNotFound();

        Sanctum::actingAs($this->owner, []);
        $this->patchJson($this->url($this->puraNavaja, $kevinId), ['activo' => true])->assertOk()->assertJsonPath('data.activo', true);

        $this->assertTrue(MembershipModel::query()->where('user_id', $kevin->id)->sole()->is_active);
        Sanctum::actingAs($kevin, []);
        $this->getJson($this->url($this->puraNavaja))->assertForbidden();
    }

    /** The owner who deactivates their own profile keeps an active owner membership (UP-05). */
    public function test_deactivating_the_owner_profile_keeps_the_owner_membership(): void
    {
        Sanctum::actingAs($this->owner, []);
        $profile = $this->postJson($this->url($this->puraNavaja, 'mi-perfil'))->json('data.id');

        $this->patchJson($this->url($this->puraNavaja, $profile), ['activo' => false])->assertOk()->assertJsonPath('data.activo', false);

        $membership = MembershipModel::query()->where('user_id', $this->owner->id)->where('barbershop_id', $this->puraNavaja)->sole();
        $this->assertSame(['owner', true], [$membership->role, $membership->is_active]);
        $this->getJson($this->url($this->puraNavaja))->assertOk();
    }

    /** A body without a valid "activo" is refused. */
    public function test_activation_requires_a_boolean(): void
    {
        Sanctum::actingAs($this->owner, []);
        $kevin = $this->createCollaboratorThroughTheApi();

        $this->patchJson($this->url($this->puraNavaja, $kevin), [])->assertUnprocessable()->assertJsonValidationErrors(['activo'], 'errores');
        $this->patchJson($this->url($this->puraNavaja, $kevin), ['activo' => 'quizás'])->assertUnprocessable();
    }

    /** A collaborator or a customer of the barbershop is refused with 403 "prohibido" in every operation (UP-03). */
    public function test_a_member_who_is_not_the_owner_is_forbidden(): void
    {
        $id = $this->createCollaboratorRow($this->puraNavaja, 'kevin@barberhub.test');

        foreach ([$this->collaborator, $this->customer] as $member) {
            Sanctum::actingAs($member, []);

            foreach ([
                $this->getJson($this->url($this->puraNavaja)),
                $this->postJson($this->url($this->puraNavaja), $this->payload(email: 'nuevo@barberhub.test')),
                $this->postJson($this->url($this->puraNavaja, 'mi-perfil')),
                $this->getJson($this->url($this->puraNavaja, $id)),
                $this->patchJson($this->url($this->puraNavaja, $id), ['activo' => false]),
                $this->putJson($this->url($this->puraNavaja, "{$id}/servicios"), ['servicios' => []]),
                $this->putJson($this->url($this->puraNavaja, "{$id}/horario"), ['turnos' => [], 'pausas' => []]),
            ] as $response) {
                $response->assertForbidden()->assertJsonPath('type', $this->problem('prohibido'));
            }
        }

        $this->assertSame(0, UserModel::query()->where('email', 'nuevo@barberhub.test')->count());
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertTrue((bool) DB::table('collaborators')->sole()->is_active);
    }

    /** A person without a role in the barbershop gets the 404 of a barbershop that does not exist, in every operation (UP-06). */
    public function test_a_person_without_a_role_in_the_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->stranger, []);
        $id = $this->createCollaboratorRow($this->puraNavaja, 'kevin@barberhub.test');

        $expected = $this->getJson($this->url((string) Str::uuid7()))->assertNotFound()->json();
        unset($expected['instance']);

        foreach ([
            $this->getJson($this->url($this->puraNavaja)),
            $this->postJson($this->url($this->puraNavaja), $this->payload(email: 'nuevo@barberhub.test')),
            $this->postJson($this->url($this->puraNavaja, 'mi-perfil')),
            $this->getJson($this->url($this->puraNavaja, $id)),
            $this->patchJson($this->url($this->puraNavaja, $id), ['activo' => false]),
            $this->putJson($this->url($this->puraNavaja, "{$id}/servicios"), ['servicios' => []]),
            $this->putJson($this->url($this->puraNavaja, "{$id}/horario"), ['turnos' => [], 'pausas' => []]),
        ] as $response) {
            $body = $response->assertNotFound()->json();
            unset($body['instance']);
            $this->assertSame($expected, $body);
        }

        $this->assertSame(0, UserModel::query()->where('email', 'nuevo@barberhub.test')->count());
    }

    /** The owner of another barbershop cannot administer this one, nor reach its collaborators from theirs (UP-06, MT-04). */
    public function test_the_owner_of_another_barbershop_finds_nothing(): void
    {
        Sanctum::actingAs($this->owner, []);
        $other = $this->createCollaboratorRow($this->fadeLab, 'otro@barberhub.test');

        $this->getJson($this->url($this->fadeLab))->assertNotFound();
        $this->postJson($this->url($this->fadeLab), $this->payload(email: 'nuevo@barberhub.test'))->assertNotFound();
        $this->postJson($this->url($this->fadeLab, 'mi-perfil'))->assertNotFound();
        $this->patchJson($this->url($this->puraNavaja, $other), ['activo' => false])->assertNotFound();
        $this->putJson($this->url($this->puraNavaja, "{$other}/servicios"), ['servicios' => []])->assertNotFound();
        $this->putJson($this->url($this->puraNavaja, "{$other}/horario"), ['turnos' => [], 'pausas' => []])->assertNotFound();

        $this->assertSame(0, UserModel::query()->where('email', 'nuevo@barberhub.test')->count());
        $this->scopeQueriesTo($this->fadeLab);
        $this->assertTrue((bool) DB::table('collaborators')->sole()->is_active);
    }

    /** Every route passes through the session, the barbershop and the role of the owner, in that order; none is public. */
    public function test_routes_are_guarded_in_order_and_none_is_public(): void
    {
        $names = array_map(
            fn (string $name): string => "catalog.collaborators.{$name}",
            ['index', 'store', 'store-own', 'show', 'update', 'update-services', 'update-schedule'],
        );

        foreach ($names as $name) {
            $route = app('router')->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame(['auth:sanctum', 'barbershop', 'role:owner'], array_values(array_filter(
                $route->gatherMiddleware(),
                fn (mixed $middleware): bool => is_string($middleware) && in_array($middleware, ['auth:sanctum', 'barbershop', 'role:owner', 'barbershop:public'], true),
            )));
        }

        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/colaboradores")->assertNotFound();
    }

    /** Create a collaborator through the API as the owner and return the identifier of the profile. */
    private function createCollaboratorThroughTheApi(string $name = 'Kevin Solano', string $email = 'kevin@barberhub.test'): string
    {
        return (string) $this->postJson($this->url($this->puraNavaja), $this->payload($name, $email))->assertCreated()->json('data.id');
    }

    /** Check that no account, role or profile came out of a refused creation. */
    private function assertNothingWasCreated(): void
    {
        $this->assertSame(0, UserModel::query()->where('email', 'kevin@barberhub.test')->count());
        $this->assertSame(3, MembershipModel::query()->where('barbershop_id', $this->puraNavaja)->count());
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('collaborators')->count());
    }

    /** Address of the collaborators the owner administers, or of a path inside them. */
    private function url(string $barbershopId, ?string $path = null): string
    {
        $url = "/api/v1/barberias/{$barbershopId}/administracion/colaboradores";

        return $path === null ? $url : "{$url}/{$path}";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /**
     * A valid body to create a collaborator, overriding only what a scenario changes.
     *
     * @return array{nombre: string, correo: string, contrasena_temporal: string}
     */
    private function payload(string $name = 'Kevin Solano', string $email = 'kevin@barberhub.test', string $password = self::TEMPORARY_PASSWORD): array
    {
        return ['nombre' => $name, 'correo' => $email, 'contrasena_temporal' => $password];
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function createBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Create a person, with a role in a barbershop unless none is given. */
    private function createMember(string $email, ?string $barbershopId, ?string $role): UserModel
    {
        $user = UserModel::query()->create(['name' => $email, 'email' => $email, 'password' => 'hash']);

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

        return $id;
    }

    /** Insert an account with a collaborator profile in a barbershop and return the identifier of the profile. */
    private function createCollaboratorRow(string $barbershopId, string $email): string
    {
        $user = UserModel::query()->create(['name' => 'Kevin Solano', 'email' => $email, 'password' => 'hash']);
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $user->id]);

        return $id;
    }
}
