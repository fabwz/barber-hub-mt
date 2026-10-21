<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBarbershopSeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

final class DemoBarbershopSeederTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    /** The tables the seeder fills. */
    private const array TABLES = ['barbershops', 'users', 'memberships', 'services', 'opening_hours', 'collaborators', 'collaborator_services', 'collaborator_shifts', 'collaborator_breaks'];

    private const string PURA_NAVAJA = '0192f0c4-0000-7000-8000-00000000b001';

    /** Seeding twice leaves the same rows in every table: nothing is duplicated (CL-08). */
    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        $first = $this->counts();

        $this->seed(DemoBarbershopSeeder::class);

        $this->assertSame(
            ['barbershops' => 3, 'users' => 10, 'memberships' => 10, 'services' => 18, 'opening_hours' => 18, 'collaborators' => 8, 'collaborator_services' => 39, 'collaborator_shifts' => 43, 'collaborator_breaks' => 35],
            $first,
        );
        $this->assertSame($first, $this->counts());
    }

    /** The default seeder calls the demo one. */
    public function test_database_seeder_loads_the_demo_barbershops(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, DB::table('barbershops')->count());
    }

    /** Each barbershop has its gateway, and Don Chepe has none (PA-02). */
    public function test_each_barbershop_has_its_gateway(): void
    {
        $this->seed(DemoBarbershopSeeder::class);

        $this->assertSame(
            ['don-chepe' => null, 'fade-lab' => 'paypal', 'pura-navaja' => 'onvo'],
            DB::table('barbershops')->orderBy('public_link')->pluck('payment_gateway', 'public_link')->all(),
        );
    }

    /** Each barbershop has exactly one active owner; its other memberships are active collaborators, and nobody has two. */
    public function test_each_barbershop_has_an_active_owner(): void
    {
        $this->seed(DemoBarbershopSeeder::class);

        foreach (DB::table('barbershops')->pluck('id') as $barbershopId) {
            $memberships = DB::table('memberships')->where('barbershop_id', $barbershopId)->get();

            $this->assertSame(1, $memberships->where('role', 'owner')->count());
            $this->assertSame([], $memberships->whereNotIn('role', ['owner', 'collaborator'])->all());
            $this->assertSame([true], $memberships->pluck('is_active')->unique()->values()->all());
        }

        $this->assertSame(10, DB::table('memberships')->count());
        $this->assertSame(10, DB::table('memberships')->distinct()->count('user_id'));
    }

    /** The demo data is fictitious: the emails use the reserved .test domain and the phones are not real numbers (RNF-23, RL-04). */
    public function test_demo_data_is_fictitious(): void
    {
        $this->seed(DemoBarbershopSeeder::class);

        foreach (DB::table('users')->pluck('email') as $email) {
            $this->assertStringEndsWith('.test', $email);
        }

        foreach ([...DB::table('users')->pluck('phone'), ...DB::table('barbershops')->pluck('phone')] as $phone) {
            $this->assertMatchesRegularExpression('/^0000-\d{4}$/', $phone);
        }
    }

    /** The shared demo password meets SE-07 and is stored hashed, so it verifies against every account, and none asks to change it. */
    public function test_demo_password_meets_the_policy_and_is_hashed(): void
    {
        $this->assertSame([], PlainPassword::missingRequirements(DemoBarbershopSeeder::PASSWORD));

        $this->seed(DemoBarbershopSeeder::class);

        foreach (DB::table('users')->get() as $user) {
            $this->assertNotSame(DemoBarbershopSeeder::PASSWORD, $user->password);
            $this->assertTrue(Hash::check(DemoBarbershopSeeder::PASSWORD, $user->password));
            $this->assertFalse($user->must_change_password);
        }
    }

    /** Pura Navaja has the 7 services of the examples, with whole-colon prices and the 60-minute ones (SV-01, SV-02). */
    public function test_pura_navaja_has_its_seven_services(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        $this->scopeQueriesTo(self::PURA_NAVAJA);

        $services = DB::table('services')->pluck('duration_minutes', 'name')->all();

        $this->assertCount(7, $services);
        $this->assertSame(30, $services['Corte infantil']);
        $this->assertSame(60, $services['Corte + barba']);
        $this->assertSame(7, DB::table('services')->where('is_active', true)->count());
        $this->assertSame([], DB::table('services')->where('price', '<=', 0)->pluck('name')->all());
    }

    /** Pura Navaja opens Monday to Friday from 9:00 to 19:00 and on Saturday from 8:00 to 17:00; Sunday is closed (HO-01). */
    public function test_pura_navaja_has_its_weekly_hours(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        $this->scopeQueriesTo(self::PURA_NAVAJA);

        $this->assertSame(
            [1 => '09:00-19:00', 2 => '09:00-19:00', 3 => '09:00-19:00', 4 => '09:00-19:00', 5 => '09:00-19:00', 6 => '08:00-17:00'],
            $this->hours(self::PURA_NAVAJA),
        );
        $this->assertArrayNotHasKey(7, $this->hours(self::PURA_NAVAJA));
    }

    /** Pura Navaja has four collaborators; Mauricio is its owner with his own profile and a break on Tuesdays from 12:00 to 13:00 (CO-01). */
    public function test_pura_navaja_has_four_collaborators_and_the_owner_is_one(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        $this->scopeQueriesTo(self::PURA_NAVAJA);

        $names = DB::table('collaborators')->join('users', 'users.id', '=', 'collaborators.user_id')->orderBy('users.name')->pluck('users.name')->all();
        $this->assertSame(['Daniela Vargas', 'Esteban Quirós', 'Kevin Mora', 'Mauricio Solano'], $names);

        $mauricio = $this->collaboratorId('mauricio@puranavaja.test');
        $this->assertSame('owner', DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('users.email', 'mauricio@puranavaja.test')->value('role'));
        $this->assertSame(['12:00-13:00'], DB::table('collaborator_breaks')->where('collaborator_id', $mauricio)->where('day_of_week', 2)->get()->map(fn (object $row): string => substr($row->starts_at, 0, 5).'-'.substr($row->ends_at, 0, 5))->all());
        $this->assertSame(0, DB::table('collaborator_breaks')->where('collaborator_id', $mauricio)->where('day_of_week', '!=', 2)->count());

        $this->assertSame(4, DB::table('collaborators')->count());
        $this->assertSame(4, DB::table('collaborators')->whereIn('id', DB::table('collaborator_breaks')->select('collaborator_id'))->count());
        $this->assertSame(4, DB::table('collaborators')->whereIn('id', DB::table('collaborator_shifts')->select('collaborator_id'))->count());
    }

    /** Kevin does not offer the children's cut, and the other three collaborators do (CO-01). */
    public function test_kevin_does_not_offer_the_children_cut(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        $this->scopeQueriesTo(self::PURA_NAVAJA);

        $infantil = DB::table('services')->where('name', 'Corte infantil')->value('id');
        $offering = DB::table('collaborator_services')
            ->join('collaborators', 'collaborators.id', '=', 'collaborator_services.collaborator_id')
            ->join('users', 'users.id', '=', 'collaborators.user_id')
            ->where('collaborator_services.service_id', $infantil)
            ->orderBy('users.name')
            ->pluck('users.name')
            ->all();

        $this->assertSame(['Daniela Vargas', 'Esteban Quirós', 'Mauricio Solano'], $offering);
        $this->assertSame(6, DB::table('collaborator_services')->where('collaborator_id', $this->collaboratorId('kevin@puranavaja.test'))->count());
    }

    /** The three barbershops have different catalogs, hours and staff (CL-08). */
    public function test_the_catalogs_hours_and_staff_differ_between_barbershops(): void
    {
        $this->seed(DemoBarbershopSeeder::class);

        $catalogs = $hours = $staff = [];
        foreach (DemoBarbershopSeeder::BARBERSHOPS as $barbershop) {
            $this->scopeQueriesTo($barbershop['id']);

            $catalogs[] = DB::table('services')->orderBy('name')->get(['name', 'duration_minutes', 'price'])->toJson();
            $hours[] = json_encode($this->hours($barbershop['id']));
            $staff[] = DB::table('collaborators')->join('users', 'users.id', '=', 'collaborators.user_id')->orderBy('users.name')->pluck('users.name')->toJson();
        }

        foreach ([$catalogs, $hours, $staff] as $values) {
            $this->assertCount(3, array_unique($values));
        }

        $this->scopeQueriesTo(DemoBarbershopSeeder::BARBERSHOPS[1]['id']);
        $this->assertSame([], DB::table('services')->whereIn('name', ['Corte infantil', 'Corte + barba'])->pluck('name')->all());
    }

    /** Every shift and every break falls inside the hours of its barbershop that day (HO-02). */
    public function test_every_shift_falls_inside_the_hours_of_its_barbershop(): void
    {
        $this->seed(DemoBarbershopSeeder::class);

        foreach (DemoBarbershopSeeder::BARBERSHOPS as $barbershop) {
            $this->scopeQueriesTo($barbershop['id']);

            $hours = DB::table('opening_hours')->get()->keyBy('day_of_week');
            $shifts = DB::table('collaborator_shifts')->get();
            $this->assertNotEmpty($shifts);

            foreach ($shifts as $shift) {
                $open = $hours->get($shift->day_of_week);

                $this->assertNotNull($open, "{$barbershop['link']} has a shift on a closed day.");
                $this->assertGreaterThanOrEqual($open->opens_at, $shift->starts_at);
                $this->assertLessThanOrEqual($open->closes_at, $shift->ends_at);
            }

            foreach (DB::table('collaborator_breaks')->get() as $break) {
                $shift = $shifts->where('collaborator_id', $break->collaborator_id)->firstWhere('day_of_week', $break->day_of_week);

                $this->assertNotNull($shift, "{$barbershop['link']} has a break on a day without shift.");
                $this->assertGreaterThanOrEqual($shift->starts_at, $break->starts_at);
                $this->assertLessThanOrEqual($shift->ends_at, $break->ends_at);
            }
        }
    }

    /**
     * The real availability over the seeded data (RF-10): "Corte + barba" (60 minutes plus 5 of cleaning) with Mauricio on a
     * Tuesday, with the clock before that date, starts at 9:00, the last space before the break is 10:45 and the last of the day 17:45.
     */
    public function test_the_availability_of_mauricio_on_a_tuesday(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        $this->scopeQueriesTo(self::PURA_NAVAJA);
        $this->app->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId(self::PURA_NAVAJA)));

        $service = DB::table('services')->where('name', 'Corte + barba')->value('id');
        $mauricio = $this->collaboratorId('mauricio@puranavaja.test');
        $now = new DateTimeImmutable('2030-03-01 08:00', new DateTimeZone('America/Costa_Rica'));

        $starts = array_map(
            fn (Slot $slot): string => $slot->start->format('H:i'),
            $this->app->make(ListAvailableSlots::class)->execute($service, '2030-03-05', $mauricio, $now),
        );

        $beforeBreak = array_values(array_filter($starts, fn (string $start): bool => $start < '12:00'));

        $this->assertSame('09:00', $starts[0]);
        $this->assertSame('10:45', end($beforeBreak));
        $this->assertSame('13:00', $starts[count($beforeBreak)]);
        $this->assertSame('17:45', end($starts));
    }

    /** Each public link opens its barbershop without a token. */
    #[DataProvider('links')]
    public function test_each_link_resolves_to_its_barbershop(string $link, string $name): void
    {
        $this->seed(DemoBarbershopSeeder::class);

        $this->getJson('/api/v1/enlaces/'.$link)
            ->assertOk()
            ->assertJsonPath('data.nombre', $name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function links(): array
    {
        return [
            'Pura Navaja' => ['pura-navaja', 'Barbería Pura Navaja'],
            'Fade Lab' => ['fade-lab', 'Fade Lab'],
            'Don Chepe' => ['don-chepe', 'Barbería Don Chepe'],
        ];
    }

    /** The seeder refuses to run in production and leaves no row (CL-08). */
    public function test_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->app->make(DemoBarbershopSeeder::class)->run();
            $this->fail('The seeder ran in production.');
        } catch (RuntimeException) {
            $this->assertSame(array_fill_keys(self::TABLES, 0), $this->counts());
        }
    }

    /** The column only admits the two gateways of PA-02; the database rejects any other. */
    public function test_database_rejects_an_unknown_gateway(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('barbershops_payment_gateway_check');

        DB::table('barbershops')->insert(['name' => 'Barbería X', 'city' => 'Ciudad Quesada', 'phone' => '0000-0099', 'public_link' => 'x', 'payment_gateway' => 'stripe']);
    }

    /**
     * Count the rows of every table the seeder fills. The tables of a barbershop are protected by row-level security,
     * so they are counted one barbershop at a time and added up.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = array_fill_keys(self::TABLES, 0);

        foreach (['barbershops', 'users', 'memberships'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        foreach (DemoBarbershopSeeder::BARBERSHOPS as $barbershop) {
            $this->scopeQueriesTo($barbershop['id']);

            foreach (array_slice(self::TABLES, 3) as $table) {
                $counts[$table] += DB::table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * The hours of a barbershop by day, as "from-to".
     *
     * @return array<int, string>
     */
    private function hours(string $barbershopId): array
    {
        $this->scopeQueriesTo($barbershopId);

        return DB::table('opening_hours')->orderBy('day_of_week')->get()
            ->mapWithKeys(fn (object $row): array => [$row->day_of_week => substr($row->opens_at, 0, 5).'-'.substr($row->closes_at, 0, 5)])
            ->all();
    }

    /** The collaborator profile of the account with that email, in the barbershop the queries are scoped to. */
    private function collaboratorId(string $email): string
    {
        return DB::table('collaborators')->join('users', 'users.id', '=', 'collaborators.user_id')->where('users.email', $email)->value('collaborators.id');
    }
}
