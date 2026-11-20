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
 * The table of the offers of the waitlist (LE-03, LE-04, DI-05, MT-02, MT-03, MT-04): its constraints refuse what the rules forbid
 * whatever writes the row, its foreign keys carry the barbershop, and its policy hides the offers of another barbershop.
 */
final class WaitlistOffersSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsWaitlist;

    private const string CHECK_VIOLATION = '23514';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string INSUFFICIENT_PRIVILEGE = '42501';

    private string $anaEntry;

    private string $beaEntry;

    /** Each barbershop has a customer waiting. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        [, $anaMembership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [, $beaMembership] = $this->insertCustomer($this->fadeLab, 'Bea Mora');
        $this->anaEntry = $this->insertWaitlistEntry($this->puraNavaja, $anaMembership, $this->degradado);
        $this->beaEntry = $this->insertWaitlistEntry($this->fadeLab, $beaMembership, $this->corte);
    }

    /** The table has the columns of the data model, and the row policy is enabled, forced and the only one. */
    public function test_the_table_has_its_columns_and_a_forced_policy(): void
    {
        $this->assertSame(
            ['id', 'barbershop_id', 'waitlist_entry_id', 'collaborator_id', 'starts_at', 'ends_at', 'expires_at', 'status', 'responded_at', 'created_at'],
            Schema::getColumnListing('waitlist_offers'),
        );
        $admin = DB::connection('pgsql_admin');
        $table = $admin->table('pg_class')->where('relname', 'waitlist_offers');
        $this->assertTrue((bool) (clone $table)->value('relrowsecurity'));
        $this->assertTrue((bool) (clone $table)->value('relforcerowsecurity'));
        $this->assertSame(['waitlist_offers_barbershop_isolation'], $admin->table('pg_policies')->where('tablename', 'waitlist_offers')->pluck('policyname')->all());
    }

    /** The four states of the data model exist and nothing else. */
    public function test_the_status_is_one_of_the_four(): void
    {
        $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'pending');
        $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'expired');
        $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'accepted', respondedAt: '2030-03-05 11:05:00-06');
        $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'rejected', respondedAt: '2030-03-05 11:05:00-06');

        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'offered'));
    }

    /** The space has a start before its end. */
    public function test_the_space_ends_after_it_starts(): void
    {
        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, startsAt: '2030-03-05 14:00:00-06', endsAt: '2030-03-05 13:00:00-06'));
        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, startsAt: '2030-03-05 13:00:00-06', endsAt: '2030-03-05 13:00:00-06'));
    }

    /** The deadline comes after the creation (LE-04). */
    public function test_the_deadline_comes_after_the_creation(): void
    {
        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, createdAt: '2030-03-05 11:00:00-06', expiresAt: '2030-03-05 11:00:00-06'));
    }

    /** Only an answer has an instant: a pending or expired offer has none, an accepted or rejected one must have it. */
    public function test_only_an_answered_offer_has_its_answer_instant(): void
    {
        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'pending', respondedAt: '2030-03-05 11:05:00-06'));
        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'expired', respondedAt: '2030-03-05 11:20:00-06'));
        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'accepted'));
        $this->assertWriteFails(self::CHECK_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela, status: 'rejected'));
    }

    /** An offer cannot point at a row of the line or a collaborator of another barbershop (MT-04). */
    public function test_an_offer_cannot_point_across_barbershops(): void
    {
        $this->assertWriteFails(self::FOREIGN_KEY_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->beaEntry, $this->daniela));
        $this->assertWriteFails(self::FOREIGN_KEY_VIOLATION, fn () => $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->mauricio));
    }

    /** A barbershop sees only its own offers, without one it sees none, and it changes or deletes none of the other's. */
    public function test_a_barbershop_sees_only_its_own_offers(): void
    {
        $own = $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela);
        $foreign = $this->insertOffer($this->fadeLab, $this->beaEntry, $this->mauricio);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$own], DB::table('waitlist_offers')->pluck('id')->all());
        $this->assertNull(DB::table('waitlist_offers')->where('id', $foreign)->first());
        $this->assertSame(0, DB::table('waitlist_offers')->where('id', $foreign)->update(['status' => 'expired']));
        $this->assertSame(0, DB::table('waitlist_offers')->where('id', $foreign)->delete());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('waitlist_offers')->count());

        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame('pending', DB::table('waitlist_offers')->where('id', $foreign)->value('status'));
    }

    /** A row cannot be written for another barbershop, nor moved to it: the check of the policy refuses both. */
    public function test_an_offer_cannot_be_written_for_or_moved_to_another_barbershop(): void
    {
        $own = $this->insertOffer($this->puraNavaja, $this->anaEntry, $this->daniela);
        $this->scopeQueriesTo($this->puraNavaja);

        $this->assertWriteFails(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('waitlist_offers')->insert($this->offerRow($this->fadeLab, $this->beaEntry, $this->mauricio)));
        $this->assertWriteFails(self::INSUFFICIENT_PRIVILEGE, fn () => DB::table('waitlist_offers')->where('id', $own)->update(['barbershop_id' => $this->fadeLab]));
    }

    /** Insert an offer written straight into the table, as the barbershop it belongs to, and return its identifier. */
    private function insertOffer(
        string $barbershopId,
        string $entryId,
        string $collaboratorId,
        string $status = 'pending',
        string $startsAt = '2030-03-05 13:00:00-06',
        string $endsAt = '2030-03-05 14:05:00-06',
        string $createdAt = '2030-03-05 11:00:00-06',
        string $expiresAt = '2030-03-05 11:15:00-06',
        ?string $respondedAt = null,
    ): string {
        $row = $this->offerRow($barbershopId, $entryId, $collaboratorId, $status, $startsAt, $endsAt, $createdAt, $expiresAt, $respondedAt);
        $this->scopeQueriesTo($barbershopId);

        DB::table('waitlist_offers')->insert($row);

        return $row['id'];
    }

    /**
     * The values of a row of the table.
     *
     * @return array{id: string, barbershop_id: string, waitlist_entry_id: string, collaborator_id: string, starts_at: string, ends_at: string, expires_at: string, status: string, responded_at: string|null, created_at: string}
     */
    private function offerRow(
        string $barbershopId,
        string $entryId,
        string $collaboratorId,
        string $status = 'pending',
        string $startsAt = '2030-03-05 13:00:00-06',
        string $endsAt = '2030-03-05 14:05:00-06',
        string $createdAt = '2030-03-05 11:00:00-06',
        string $expiresAt = '2030-03-05 11:15:00-06',
        ?string $respondedAt = null,
    ): array {
        return [
            'id' => (string) Str::uuid7(),
            'barbershop_id' => $barbershopId,
            'waitlist_entry_id' => $entryId,
            'collaborator_id' => $collaboratorId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'expires_at' => $expiresAt,
            'status' => $status,
            'responded_at' => $respondedAt,
            'created_at' => $createdAt,
        ];
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
