<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\Clock;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * Joining, listing and leaving the waitlist (RF-30, LE-01, LE-02, LE-06, CL-04, UP-07, UP-09, RD-18). It uses the people of the
 * permission tests: Ana and Luis are customers of Pura Navaja, Bea of Fade Lab, and Daniela and Kevin its collaborators. Today is
 * Friday 1 March 2030, 8:00. Tuesday 5 March has free spaces for "Degradado"; Wednesday 6 March has none, because nobody works.
 */
final class WaitlistEntriesTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    private const string WITH_SPACES = '2030-03-05';

    private const string WITHOUT_SPACES = '2030-03-06';

    private string $newcomer;

    /** Both barbershops have the waitlist on, and there is a person with no role anywhere. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAccessScenario();
        DB::table('barbershops')->update(['waitlist_enabled' => true]);
        $this->newcomer = $this->insertUser('Nora Vega');
        $this->tokens['newcomer'] = UserModel::query()->findOrFail($this->newcomer)->createToken('prueba')->plainTextToken;
        // The scenario gives Ana rows and an offer; these tests start from an empty line unless they say so.
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('waitlist_offers')->delete();
        $this->entries()->delete();
    }

    /** A person with no role joins: their customer role is created with the row, for any collaborator, waiting and stamped with the instant (UP-09, LE-01, LE-02). */
    public function test_a_person_without_a_role_joins_and_becomes_a_customer(): void
    {
        $response = $this->join('newcomer', ['servicio' => $this->degradado, 'fecha' => self::WITHOUT_SPACES])->assertCreated();

        $response->assertJsonPath('data.servicio', $this->degradado)
            ->assertJsonPath('data.barbero', null)
            ->assertJsonPath('data.barbero_nombre', null)
            ->assertJsonPath('data.fecha', self::WITHOUT_SPACES)
            ->assertJsonPath('data.estado', 'esperando')
            ->assertJsonPath('data.ingreso', '2030-03-01T08:00:00-06:00');
        $this->assertSame(['customer'], $this->rolesOf($this->newcomer, $this->puraNavaja));
        $row = $this->entries()->where('id', $response->json('data.id'))->first();
        $this->assertSame([$this->puraNavaja, 'waiting', null], [$row?->barbershop_id, $row?->status, $row?->collaborator_id]);
        $this->assertSame($this->rolesOf($this->newcomer, $this->puraNavaja)[0], 'customer');
    }

    /** A customer who already has a role joins for one collaborator without a second role. */
    public function test_a_customer_joins_for_a_collaborator(): void
    {
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => self::WITHOUT_SPACES, 'barbero' => $this->daniela])
            ->assertCreated()
            ->assertJsonPath('data.barbero', $this->daniela)
            ->assertJsonPath('data.barbero_nombre', 'Daniela');

        $this->assertSame(['customer'], $this->rolesOf($this->ana, $this->puraNavaja));
    }

    /** The owner and a collaborator of the barbershop get 403 and nothing is created (UP-09). */
    public function test_the_owner_and_a_collaborator_are_refused(): void
    {
        foreach (['owner', 'collaborator', 'assigned_collaborator'] as $actor) {
            $this->join($actor, ['servicio' => $this->degradado, 'fecha' => self::WITHOUT_SPACES])
                ->assertForbidden()
                ->assertJsonPath('type', 'http://localhost/problemas/prohibido');
        }

        $this->assertSame(0, $this->entries()->count());
        $this->assertSame([], $this->rolesOf($this->owner, $this->puraNavaja, 'customer'));
    }

    /** If joining fails, the role that the first use would have created is not left behind (UP-09). */
    public function test_a_failed_join_leaves_no_membership(): void
    {
        // With free spaces.
        $this->join('newcomer', ['servicio' => $this->degradado, 'fecha' => self::WITH_SPACES])->assertConflict();
        $this->assertSame([], $this->rolesOf($this->newcomer, $this->puraNavaja));

        // With a collaborator of another barbershop, and with a service of another barbershop.
        $this->join('newcomer', ['servicio' => $this->degradado, 'fecha' => self::WITHOUT_SPACES, 'barbero' => $this->mauricio])->assertNotFound();
        $this->join('newcomer', ['servicio' => $this->corte, 'fecha' => self::WITHOUT_SPACES])->assertNotFound();
        $this->assertSame(0, $this->roleCount($this->newcomer, $this->puraNavaja));
        $this->assertSame(0, $this->entries()->count());
    }

    /** With free spaces for the combination nobody joins: the error shows up to 3 of them, the closest to the start of the day, and nothing is created (LE-01). */
    public function test_with_free_spaces_it_shows_them_instead_of_joining(): void
    {
        $response = $this->join('customer', ['servicio' => $this->degradado, 'fecha' => self::WITH_SPACES])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/conflicto');

        $spaces = $response->json('espacios');
        $this->assertCount(3, $spaces);
        $this->assertSame(['barbero', 'inicio', 'fin'], array_keys($spaces[0]));
        $this->assertSame('2030-03-05T09:00:00-06:00', $spaces[0]['inicio']);
        $this->assertSame('2030-03-05T09:45:00-06:00', $spaces[0]['fin']);
        $starts = array_column($spaces, 'inicio');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
        $this->assertSame(0, $this->entries()->count());
    }

    /** Asking for one collaborator only looks at their spaces: Kevin finishes at noon, so after it nothing is free and, with Daniela's day taken, the line is open. */
    public function test_the_spaces_shown_are_those_of_the_collaborator_asked_for(): void
    {
        $response = $this->join('customer', ['servicio' => $this->degradado, 'fecha' => self::WITH_SPACES, 'barbero' => $this->kevin])->assertConflict();

        $this->assertSame([$this->kevin], array_values(array_unique(array_column($response->json('espacios'), 'barbero'))));
    }

    /** The body is validated before anything is written, and a day that has passed is refused on its field (LE-01). */
    public function test_the_body_is_validated(): void
    {
        $this->join('customer', [])->assertUnprocessable()->assertJsonStructure(['errores' => ['servicio', 'fecha']]);
        $this->join('customer', ['servicio' => 'x', 'fecha' => '05/03/2030', 'barbero' => 'y'])->assertUnprocessable()->assertJsonStructure(['errores' => ['servicio', 'fecha', 'barbero']]);
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-02-28'])->assertUnprocessable()->assertJsonPath('errores.fecha.0', __('waitlist.date_in_past'));
        // Nothing was written by the refused ones; today itself is accepted (nobody works on Fridays, so it has no spaces).
        $this->assertSame(0, $this->entries()->count());
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-01'])->assertCreated();
    }

    /** The same service, day and collaborator twice is refused; another day or another collaborator is a different row. */
    public function test_the_same_combination_twice_is_refused(): void
    {
        $payload = ['servicio' => $this->degradado, 'fecha' => self::WITHOUT_SPACES];
        $this->join('customer', $payload)->assertCreated();

        $this->join('customer', $payload)->assertConflict()->assertJsonPath('detail', __('waitlist.duplicate_entry'));
        $this->join('customer', [...$payload, 'barbero' => $this->daniela])->assertCreated();
        $this->join('customer', [...$payload, 'fecha' => '2030-03-07', 'barbero' => $this->daniela])->assertCreated();
        $this->assertSame(3, $this->entries()->count());
    }

    /** A customer has at most 3 active rows: the fourth is refused, and one that was left no longer counts (LE-01). */
    public function test_the_fourth_active_row_is_refused(): void
    {
        $first = $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-06'])->assertCreated()->json('data.id');
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-07'])->assertCreated();
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-08'])->assertCreated();

        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-09'])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/conflicto')
            ->assertJsonPath('detail', __('waitlist.limit_reached'));
        $this->assertSame(3, $this->entries()->count());

        $this->sendAs('customer', 'DELETE', "/api/v1/barberias/{$this->puraNavaja}/filas/{$first}")->assertNoContent();
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-09'])->assertCreated();
        $this->assertSame(3, $this->entries()->whereIn('status', ['waiting', 'offered'])->count());
    }

    /** An offered row is active too, and a fulfilled one is not (LE-01). */
    public function test_offered_rows_count_and_fulfilled_ones_do_not(): void
    {
        $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-06', status: 'offered');
        $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-07', status: 'fulfilled');
        $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-08', status: 'waiting');
        $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-09', status: 'waiting');

        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-12'])->assertConflict();
    }

    /** The limit is per barbershop: three rows in Fade Lab do not stop a customer from joining Pura Navaja (LE-01, UP-07). */
    public function test_the_limit_is_per_barbershop(): void
    {
        foreach (['2030-03-06', '2030-03-07', '2030-03-08'] as $date) {
            $this->insertWaitlistEntry($this->fadeLab, DB::table('memberships')->where('barbershop_id', $this->fadeLab)->where('role', 'customer')->value('id'), $this->corte, date: $date);
        }

        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => self::WITHOUT_SPACES])->assertCreated();
    }

    /** With the waitlist off nobody joins and nothing is created, not even the role; the rows already there are untouched and can be left (RF-09). */
    public function test_with_the_waitlist_off_nobody_joins_but_anyone_can_leave(): void
    {
        $row = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['waitlist_enabled' => false]);

        $this->join('newcomer', ['servicio' => $this->degradado, 'fecha' => self::WITHOUT_SPACES])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/conflicto')
            ->assertJsonPath('detail', __('waitlist.disabled'));
        $this->assertSame([], $this->rolesOf($this->newcomer, $this->puraNavaja));
        $this->assertSame(1, $this->entries()->count());
        $this->sendAs('customer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertOk()->assertJsonCount(1, 'data');

        $this->sendAs('customer', 'DELETE', "/api/v1/barberias/{$this->puraNavaja}/filas/{$row}")->assertNoContent();
        $this->assertSame('left', $this->entries()->where('id', $row)->value('status'));
    }

    /** The line is ordered by the instant of joining, whatever the order the rows were written in (LE-02). */
    public function test_my_rows_come_in_the_order_they_joined(): void
    {
        $late = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-06', joinedAt: '2030-03-01 09:30:00-06');
        $first = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-07', joinedAt: '2030-03-01 07:15:00-06');
        $middle = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-08', joinedAt: '2030-03-01 08:45:00-06');

        $response = $this->sendAs('customer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertOk();

        $this->assertSame([$first, $middle, $late], $response->json('data.*.id'));
        $this->assertSame(['2030-03-01T07:15:00-06:00', '2030-03-01T08:45:00-06:00', '2030-03-01T09:30:00-06:00'], $response->json('data.*.ingreso'));
    }

    /** The rows that join through the API take the instant of the request, so a later one sorts after an earlier one (LE-02). */
    public function test_a_row_joined_later_sorts_after(): void
    {
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-06'])->assertCreated();
        $this->travelClockTo('2030-03-01 08:05:00');
        $this->join('customer', ['servicio' => $this->degradado, 'fecha' => '2030-03-07'])->assertCreated();

        $this->assertSame(['2030-03-06', '2030-03-07'], $this->sendAs('customer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->json('data.*.fecha'));
    }

    /** A customer lists only their own active rows: not another customer's, not another barbershop's, not the ones they left (CL-04). */
    public function test_a_customer_lists_only_their_own_active_rows(): void
    {
        $mine = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-06');
        $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-07', status: 'left');
        $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-08', status: 'fulfilled');
        $this->insertWaitlistEntry($this->puraNavaja, $this->luisMembership, $this->degradado, date: '2030-03-06');
        $this->insertWaitlistEntry($this->fadeLab, (string) DB::table('memberships')->where('barbershop_id', $this->fadeLab)->where('role', 'customer')->value('id'), $this->corte);

        $this->sendAs('customer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
        $this->sendAs('other_customer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertOk()->assertJsonCount(1, 'data');
    }

    /** The list names the barber each row asked for, and only that: «cualquiera» has no name, and no other data of the barber leaves (CL-04). */
    public function test_the_list_names_the_barber_of_each_row(): void
    {
        $named = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, collaboratorId: $this->daniela, date: '2030-03-06');
        $any = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-07');

        $rows = $this->sendAs('customer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertOk()->json('data');

        $this->assertSame([$named, $any], array_column($rows, 'id'));
        $this->assertSame(['Daniela', null], array_column($rows, 'barbero_nombre'));
        $this->assertSame(['id', 'servicio', 'barbero', 'barbero_nombre', 'fecha', 'estado', 'ingreso'], array_keys($rows[0]));
    }

    /** A person with no role reads an empty list, and reading creates no role (RD-18); the owner and a collaborator are refused. */
    public function test_a_person_without_a_role_lists_nothing_and_staff_is_refused(): void
    {
        $this->sendAs('newcomer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertOk()->assertExactJson(['data' => []]);
        $this->assertSame([], $this->rolesOf($this->newcomer, $this->puraNavaja));

        foreach (['owner', 'collaborator'] as $actor) {
            $this->sendAs($actor, 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertForbidden();
        }
    }

    /** Leaving puts the row in `left`, keeps it, and doing it twice answers the same (RF-30). */
    public function test_a_customer_leaves_their_row(): void
    {
        $row = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado);
        $url = "/api/v1/barberias/{$this->puraNavaja}/filas/{$row}";

        $this->sendAs('customer', 'DELETE', $url)->assertNoContent();
        $this->sendAs('customer', 'DELETE', $url)->assertNoContent();

        $this->assertSame('left', $this->entries()->where('id', $row)->value('status'));
        $this->sendAs('customer', 'GET', "/api/v1/barberias/{$this->puraNavaja}/mis-filas")->assertJsonCount(0, 'data');
    }

    /** An offered row can be left too, but one that ended in a reservation cannot (RF-30). */
    public function test_an_offered_row_is_left_and_a_fulfilled_one_is_not(): void
    {
        $offered = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, status: 'offered');
        $fulfilled = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-06', status: 'fulfilled');

        $this->sendAs('customer', 'DELETE', "/api/v1/barberias/{$this->puraNavaja}/filas/{$offered}")->assertNoContent();
        $this->sendAs('customer', 'DELETE', "/api/v1/barberias/{$this->puraNavaja}/filas/{$fulfilled}")->assertConflict();

        $this->assertSame(['left', 'fulfilled'], [$this->entries()->where('id', $offered)->value('status'), $this->entries()->where('id', $fulfilled)->value('status')]);
    }

    /** A customer neither sees nor modifies the rows of another: the answer is the one of a row that does not exist, and the row stays (CL-04, MT-03). */
    public function test_a_customer_cannot_leave_the_row_of_another(): void
    {
        $row = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado);
        $absent = $this->sendAs('other_customer', 'DELETE', "/api/v1/barberias/{$this->puraNavaja}/filas/".(string) Str::uuid7())->assertNotFound();

        $foreign = $this->sendAs('other_customer', 'DELETE', "/api/v1/barberias/{$this->puraNavaja}/filas/{$row}")->assertNotFound();
        $outsider = $this->sendAs('newcomer', 'DELETE', "/api/v1/barberias/{$this->puraNavaja}/filas/{$row}")->assertNotFound();

        $this->assertSame($this->problemOf($absent), $this->problemOf($foreign));
        $this->assertSame($this->problemOf($absent), $this->problemOf($outsider));
        $this->assertSame('waiting', $this->entries()->where('id', $row)->value('status'));
        $this->assertSame([], $this->rolesOf($this->newcomer, $this->puraNavaja));
    }

    /** A row of one barbershop is not reached through another, and a customer of one does not see the line of the other (MT-02, MT-03). */
    public function test_the_rows_do_not_cross_barbershops(): void
    {
        $row = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado);

        $this->sendAs('customer', 'DELETE', "/api/v1/barberias/{$this->fadeLab}/filas/{$row}")->assertNotFound();
        $this->sendAs('customer', 'GET', "/api/v1/barberias/{$this->fadeLab}/mis-filas")->assertOk()->assertExactJson(['data' => []]);
        $this->assertSame('waiting', $this->entries()->where('id', $row)->value('status'));
        // Ana is not a customer of Fade Lab: joining there creates that role, in that barbershop only.
        $this->assertSame([], $this->rolesOf($this->ana, $this->fadeLab));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function join(string $actor, array $payload): TestResponse
    {
        return $this->sendAs($actor, 'POST', "/api/v1/barberias/{$this->puraNavaja}/filas", $payload);
    }

    /** How many roles a person has in a barbershop. */
    private function roleCount(string $userId, string $barbershopId): int
    {
        return count($this->rolesOf($userId, $barbershopId));
    }

    /** The rows of the waitlist of a barbershop (Pura Navaja by default), read as that barbershop. */
    private function entries(?string $barbershopId = null): Builder
    {
        $this->scopeQueriesTo($barbershopId ?? $this->puraNavaja);

        return DB::table('waitlist_entries');
    }

    /**
     * The roles a person has in a barbershop, optionally only those of a kind.
     *
     * @return list<string>
     */
    private function rolesOf(string $userId, string $barbershopId, ?string $role = null): array
    {
        $this->scopeQueriesTo(null);
        $query = DB::table('memberships')->where('user_id', $userId)->where('barbershop_id', $barbershopId);

        return array_values(($role === null ? $query : $query->where('role', $role))->pluck('role')->all());
    }

    /** Move the clock of the application to a moment of Costa Rica. */
    private function travelClockTo(string $moment): void
    {
        $this->app->instance(Clock::class, new class($moment) implements Clock
        {
            public function __construct(private readonly string $moment) {}

            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable($this->moment, new \DateTimeZone('America/Costa_Rica'));
            }
        });
    }
}
