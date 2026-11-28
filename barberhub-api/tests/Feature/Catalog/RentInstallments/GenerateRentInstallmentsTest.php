<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\RentInstallments;

use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Catalog\Barbershops\Application\DTOs\RegisterBarbershopCommand;
use Src\Catalog\Barbershops\Application\UseCases\RegisterBarbershop;
use Src\Catalog\RentInstallments\Application\UseCases\CountPendingRentMonths;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The daily task of the rent (RF-57, PL-05): what it creates, that it is idempotent and that it runs with no session. */
final class GenerateRentInstallmentsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new MutableClock(new DateTimeImmutable('2026-11-10 08:00:00-06:00'));
        $this->app->instance(Clock::class, $this->clock);
        config()->set('rent.monthly_amount', 25000);
    }

    /** Registered on the 10th: due the 10th of every month; the next one appears the day after the latest falls due. */
    public function test_a_barbershop_registered_on_the_10th_falls_due_on_the_10th_each_month(): void
    {
        $id = $this->barbershop('2026-11-10 09:00:00-06:00');

        $this->runOn('2026-11-10 08:00:00-06:00');
        $this->assertSame(['2026-11-10'], $this->dueDates($id));

        $this->runOn('2026-11-11 08:00:00-06:00');
        $this->runOn('2026-12-11 08:00:00-06:00');
        $this->assertSame(['2026-11-10', '2026-12-10', '2027-01-10'], $this->dueDates($id));
    }

    /** Registered on 31 January: February falls due on the 28th and March goes back to the 31st. */
    public function test_a_barbershop_registered_on_31_january_falls_due_on_the_last_day_of_short_months(): void
    {
        $id = $this->barbershop('2026-01-31 09:00:00-06:00');

        foreach (['2026-01-31', '2026-02-01', '2026-03-01', '2026-04-01'] as $day) {
            $this->runOn("{$day} 08:00:00-06:00");
        }

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $this->dueDates($id));
    }

    /** In a leap year February falls due on the 29th. */
    public function test_february_of_a_leap_year_falls_due_on_the_29th(): void
    {
        $id = $this->barbershop('2028-01-31 09:00:00-06:00');

        foreach (['2028-01-31', '2028-02-01'] as $day) {
            $this->runOn("{$day} 08:00:00-06:00");
        }

        $this->assertSame(['2028-01-31', '2028-02-29'], $this->dueDates($id));
    }

    /** Registered on the 30th: February falls due on its last day, 28 or 29, and the 30th comes back after. */
    public function test_a_barbershop_registered_on_the_30th_falls_due_on_the_last_day_of_february(): void
    {
        $id = $this->barbershop('2026-01-30 09:00:00-06:00');

        foreach (['2026-01-30', '2026-01-31', '2026-03-01'] as $day) {
            $this->runOn("{$day} 08:00:00-06:00");
        }

        $this->assertSame(['2026-01-30', '2026-02-28', '2026-03-30'], $this->dueDates($id));
    }

    /** Two runs the same day create nothing more, before and after the second month appears (unique key). */
    public function test_running_twice_the_same_day_does_not_duplicate(): void
    {
        $id = $this->barbershop('2026-11-10 09:00:00-06:00');

        $this->runOn('2026-11-10 08:00:00-06:00');
        $this->runOn('2026-11-10 20:00:00-06:00');
        $this->assertSame(['2026-11-10'], $this->dueDates($id));

        $this->runOn('2026-11-11 08:00:00-06:00');
        $this->runOn('2026-11-11 08:00:00-06:00');
        $this->assertSame(['2026-11-10', '2026-12-10'], $this->dueDates($id));
    }

    /** The unique key (barbershop, due date) refuses a second installment of the same month even if the code were bypassed. */
    public function test_the_database_refuses_two_installments_of_the_same_month(): void
    {
        $id = $this->barbershop('2026-11-10 09:00:00-06:00');
        $this->runOn('2026-11-10 08:00:00-06:00');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->scope($id, fn () => DB::table('rent_installments')->insert(['barbershop_id' => $id, 'amount' => 1000, 'due_on' => '2026-11-10']));
    }

    /** The limit is midnight of Costa Rica: at 05:59 UTC it is still the 10th there and nothing is created; at 06:00 UTC the next month is. */
    public function test_the_midnight_of_costa_rica_is_the_limit(): void
    {
        $id = $this->barbershop('2026-11-10 09:00:00-06:00');
        $this->runOn('2026-11-10 08:00:00-06:00');

        $this->runOn('2026-11-11 05:59:59+00:00');
        $this->assertSame(['2026-11-10'], $this->dueDates($id));

        $this->runOn('2026-11-11 06:00:00+00:00');
        $this->assertSame(['2026-11-10', '2026-12-10'], $this->dueDates($id));
    }

    /** A barbershop with no installment, such as one registered before this task existed, only gets its first one, the next anniversary: no debt backwards. */
    public function test_a_barbershop_without_installments_gets_only_its_first_one(): void
    {
        $old = $this->barbershop('2026-01-15 09:00:00-06:00');
        $recent = $this->barbershop('2026-11-20 09:00:00-06:00');

        $this->runOn('2026-11-20 08:00:00-06:00');

        $this->assertSame(['2026-12-15'], $this->dueDates($old));
        $this->assertSame(['2026-11-20'], $this->dueDates($recent));
    }

    /** Each installment keeps the amount it was created with: changing the rent afterwards only affects the new ones (PD-09). */
    public function test_an_installment_keeps_its_amount_when_the_rent_changes(): void
    {
        $id = $this->barbershop('2026-11-10 09:00:00-06:00');
        $this->runOn('2026-11-10 08:00:00-06:00');

        config()->set('rent.monthly_amount', 30000);
        $this->runOn('2026-11-11 08:00:00-06:00');

        $amounts = $this->scope($id, fn (): array => DB::table('rent_installments')->orderBy('due_on')->pluck('amount')->all());
        $this->assertSame([25000, 30000], $amounts);
    }

    /** The command works with no session and no request: nobody is authenticated and, outside each barbershop's scope, no row is within reach. */
    public function test_the_command_runs_without_a_session(): void
    {
        $first = $this->barbershop('2026-11-10 09:00:00-06:00');
        $second = $this->barbershop('2026-11-10 10:00:00-06:00');

        $this->assertFalse(Auth::check());
        $this->assertSame(0, Artisan::call('rent:generate-installments'));

        $this->assertFalse(Auth::check());
        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('rent_installments')->count());
        $this->assertSame(['2026-11-10'], $this->dueDates($first));
        $this->assertSame(['2026-11-10'], $this->dueDates($second));
    }

    /** Registering a barbershop creates its first installment in the same transaction, pending and with the rent in force (RF-01 step 3). */
    public function test_registering_a_barbershop_creates_its_first_installment(): void
    {
        $this->app->forgetInstance(Clock::class);
        $this->app->bind(Clock::class, fn (): Clock => new MutableClock(new DateTimeImmutable));
        $ownerId = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $ownerId, 'name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => 'hash']);

        $barbershop = $this->app->make(RegisterBarbershop::class)->execute(new RegisterBarbershopCommand('Barbería Pura Navaja', 'San Carlos', '2460-0000', 'Ana Mora', 'ana@barberhub.test', '8888-0000', $ownerId))->barbershop;

        $rows = $this->scope($barbershop->id->value, fn (): array => DB::table('rent_installments')->get()->all());
        $this->assertCount(1, $rows);
        $this->assertSame($barbershop->id->value, $rows[0]->barbershop_id);
        $this->assertSame(25000, $rows[0]->amount);
        $this->assertSame('pending', $rows[0]->status);
        $this->assertSame((new DateTimeImmutable('now', new \DateTimeZone('America/Costa_Rica')))->format('Y-m-d'), $rows[0]->due_on);
    }

    /** The pending months are counted from the overdue unpaid installments: 0, 1 and 3. */
    public function test_pending_months_are_counted_not_stored(): void
    {
        $id = $this->barbershop('2026-08-10 09:00:00-06:00');
        $count = fn (string $now): int => $this->scope($id, fn (): int => $this->app->make(CountPendingRentMonths::class)->execute($id, new DateTimeImmutable($now)));

        $this->insertInstallment($id, '2026-08-10');
        $this->assertSame(0, $count('2026-08-10 12:00:00-06:00'));
        $this->assertSame(1, $count('2026-08-11 00:00:00-06:00'));

        $this->insertInstallment($id, '2026-09-10');
        $this->insertInstallment($id, '2026-10-10');
        $this->insertInstallment($id, '2026-11-10', 'paid');
        $this->insertInstallment($id, '2026-12-10');
        $this->assertSame(3, $count('2026-11-20 10:00:00-06:00'));
    }

    /** Run the command at an instant. */
    private function runOn(string $instant): void
    {
        $this->clock->moveTo(new DateTimeImmutable($instant));
        $this->assertSame(0, Artisan::call('rent:generate-installments'));
    }

    /** Insert a barbershop registered at an instant, as the application role does. */
    private function barbershop(string $registeredAt): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => 'Barbería '.$id, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => 'b-'.$id, 'created_at' => $registeredAt, 'updated_at' => $registeredAt]);

        return $id;
    }

    /**
     * The due dates of the barbershop, oldest first, read as that barbershop.
     *
     * @return list<string>
     */
    private function dueDates(string $barbershopId): array
    {
        return array_values($this->scope($barbershopId, fn (): array => DB::table('rent_installments')->orderBy('due_on')->pluck('due_on')->all()));
    }

    private function insertInstallment(string $barbershopId, string $dueOn, string $status = 'pending'): void
    {
        $this->scope($barbershopId, fn () => DB::table('rent_installments')->insert(['barbershop_id' => $barbershopId, 'amount' => 25000, 'due_on' => $dueOn, 'status' => $status]));
    }

    /**
     * Run as one barbershop, which is what the daily task does for each one.
     *
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    private function scope(string $barbershopId, \Closure $operation): mixed
    {
        return $this->app->make(BarbershopProcessScope::class)->run($barbershopId, $operation);
    }
}
