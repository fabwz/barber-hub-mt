<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoBarbershopSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class DemoBarbershopSeederTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** Seeding twice leaves the same rows: nothing is duplicated, not accounts, barbershops or memberships (CL-08). */
    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seed(DemoBarbershopSeeder::class);
        $first = $this->counts();

        $this->seed(DemoBarbershopSeeder::class);

        $this->assertSame(['barbershops' => 3, 'users' => 3, 'memberships' => 3], $first);
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

    /** Each barbershop has exactly one active owner, and no other membership. */
    public function test_each_barbershop_has_an_active_owner(): void
    {
        $this->seed(DemoBarbershopSeeder::class);

        foreach (DB::table('barbershops')->pluck('id') as $barbershopId) {
            $memberships = DB::table('memberships')->where('barbershop_id', $barbershopId)->get();

            $this->assertCount(1, $memberships);
            $this->assertSame(['owner'], $memberships->pluck('role')->all());
            $this->assertSame([true], $memberships->pluck('is_active')->all());
        }

        $this->assertSame(3, DB::table('memberships')->distinct()->count('user_id'));
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

    /** The shared demo password meets SE-07 and is stored hashed, so it verifies against every account. */
    public function test_demo_password_meets_the_policy_and_is_hashed(): void
    {
        $this->assertSame([], PlainPassword::missingRequirements(DemoBarbershopSeeder::PASSWORD));

        $this->seed(DemoBarbershopSeeder::class);

        foreach (DB::table('users')->pluck('password') as $hash) {
            $this->assertNotSame(DemoBarbershopSeeder::PASSWORD, $hash);
            $this->assertTrue(Hash::check(DemoBarbershopSeeder::PASSWORD, $hash));
        }
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
            $this->assertSame(['barbershops' => 0, 'users' => 0, 'memberships' => 0], $this->counts());
        }
    }

    /** The column only admits the two gateways of PA-02; the database rejects any other. */
    public function test_database_rejects_an_unknown_gateway(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('barbershops_payment_gateway_check');

        DB::table('barbershops')->insert(['name' => 'Barbería X', 'city' => 'Ciudad Quesada', 'phone' => '0000-0099', 'public_link' => 'x', 'payment_gateway' => 'stripe']);
    }

    /** @return array{barbershops: int, users: int, memberships: int} */
    private function counts(): array
    {
        return [
            'barbershops' => DB::table('barbershops')->count(),
            'users' => DB::table('users')->count(),
            'memberships' => DB::table('memberships')->count(),
        ];
    }
}
