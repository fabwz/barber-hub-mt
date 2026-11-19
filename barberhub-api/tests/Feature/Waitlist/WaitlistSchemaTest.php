<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * The table of the rows of the waitlist (LE-01, LE-02, MT-02, MT-03, MT-04): its constraints refuse what the rules forbid whatever
 * writes the row, its foreign keys carry the barbershop, and its policy hides the rows of another barbershop.
 */
final class WaitlistSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsWaitlist;

    private const string CHECK_VIOLATION = '23514';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string INSUFFICIENT_PRIVILEGE = '42501';

    private string $anaMembership;

    private string $beaMembership;

    /** Each barbershop has a customer. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        [, $this->anaMembership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [, $this->beaMembership] = $this->insertCustomer($this->fadeLab, 'Bea Mora');
    }

    /** The table exists with the columns of the data model, and the row policy is enabled and forced. */
    public function test_the_table_has_its_columns_and_a_forced_policy(): void
    {
        $this->assertSame(
            ['id', 'barbershop_id', 'customer_membership_id', 'service_id', 'collaborator_id', 'desired_date', 'status', 'joined_at', 'created_at', 'updated_at'],
            Schema::getColumnListing('waitlist_entries'),
        );
        $admin = DB::connection('pgsql_admin');
        $table = $admin->table('pg_class')->where('relname', 'waitlist_entries');
        $this->assertTrue((bool) (clone $table)->value('relrowsecurity'));
        $this->assertTrue((bool) (clone $table)->value('relforcerowsecurity'));
        $this->assertSame(['waitlist_entries_barbershop_isolation'], $admin->table('pg_policies')->where('tablename', 'waitlist_entries')->pluck('policyname')->all());
    }

    /** A row for "any collaborator" has no collaborator, and one for a collaborator of its own barbershop is accepted (LE-01). */
    public function test_a_row_may_ask_for_any_collaborator_or_for_one_of_its_barbershop(): void
    {
        $any = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado);
        $one = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, $this->daniela);

        $this->assertNull(DB::table('waitlist_entries')->where('id', $any)->value('collaborator_id'));
        $this->assertSame($this->daniela, DB::table('waitlist_entries')->where('id', $one)->value('collaborator_id'));
    }

    /** Only the four states of the data model exist. */
    public function test_the_status_is_one_of_the_four(): void
    {
        foreach (['waiting', 'offered', 'fulfilled', 'left'] as $status) {
            $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, status: $status);
        }

        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, status: 'pending'));
    }

    /** A row cannot point at a customer, a service or a collaborator of another barbershop: the foreign keys carry the barbershop (MT-04). */
    public function test_a_row_cannot_point_across_barbershops(): void
    {
        $this->assertWriteFails(self::FOREIGN_KEY_VIOLATION, fn () => $this->insertWaitlistEntry($this->puraNavaja, $this->beaMembership, $this->degradado));
        $this->assertWriteFails(self::FOREIGN_KEY_VIOLATION, fn () => $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->corte));
        $this->assertWriteFails(self::FOREIGN_KEY_VIOLATION, fn () => $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, $this->mauricio));
    }

    /** With the context of a barbershop only its rows are visible; without one, none; and the row of the other is not found by its id (MT-02, MT-03). */
    public function test_a_barbershop_sees_only_its_own_rows(): void
    {
        $own = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado);
        $other = $this->insertWaitlistEntry($this->fadeLab, $this->beaMembership, $this->corte);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$own], DB::table('waitlist_entries')->pluck('id')->all());
        $this->assertNull(DB::table('waitlist_entries')->where('id', $other)->first());

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame([$other], DB::table('waitlist_entries')->pluck('id')->all());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('waitlist_entries')->count());
    }

    /** An update or a delete aimed at a row of another barbershop touches nothing. */
    public function test_a_barbershop_cannot_change_or_delete_the_rows_of_another(): void
    {
        $other = $this->insertWaitlistEntry($this->fadeLab, $this->beaMembership, $this->corte);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('waitlist_entries')->where('id', $other)->update(['status' => 'left']));
        $this->assertSame(0, DB::table('waitlist_entries')->where('id', $other)->delete());

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame('waiting', DB::table('waitlist_entries')->where('id', $other)->value('status'));
    }

    /** A row cannot be written for another barbershop, nor moved to it: the check of the policy refuses both. */
    public function test_a_row_cannot_be_written_for_or_moved_to_another_barbershop(): void
    {
        $own = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado);
        $this->scopeQueriesTo($this->puraNavaja);

        $this->assertWriteFails(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('waitlist_entries')->insert([
            'id' => (string) Str::uuid7(), 'barbershop_id' => $this->fadeLab, 'customer_membership_id' => $this->beaMembership,
            'service_id' => $this->corte, 'desired_date' => '2030-03-05', 'status' => 'waiting', 'joined_at' => '2030-03-01 08:00:00-06',
        ]));
        $this->assertWriteFails(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('waitlist_entries')->where('id', $own)->update(['barbershop_id' => $this->fadeLab]));
    }

    /**
     * Run a write that the database must refuse, inside a savepoint so the transaction of the test goes on, and check its code.
     *
     * @param  Closure(): mixed  $write
     */
    private function assertWriteFails(string $state, Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("The write was accepted; it should fail with {$state}.");
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0] ?? null, $exception->getMessage());
        }
    }
}
