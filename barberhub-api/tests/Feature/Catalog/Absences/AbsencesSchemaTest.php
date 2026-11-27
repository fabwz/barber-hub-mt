<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Absences;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The table of the absences keeps its range sane, its collaborator in the same barbershop and its rows isolated (RF-07, MT-04). */
final class AbsencesSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private string $daniela;

    private string $mauricio;

    /** Two barbershops with a collaborator each; the queries of the test reach the first one. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->daniela = $this->insertCollaborator($this->puraNavaja);
        $this->mauricio = $this->insertCollaborator($this->fadeLab);
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** The table has the planned columns and no others. */
    public function test_table_has_the_planned_columns(): void
    {
        $this->assertEqualsCanonicalizing(
            ['id', 'barbershop_id', 'collaborator_id', 'starts_at', 'ends_at', 'reason', 'created_at', 'updated_at'],
            Schema::connection('pgsql_admin')->getColumnListing('collaborator_absences'),
        );
    }

    /** The application role stores an absence with a version 7 identifier by default, and two may overlap. */
    public function test_an_absence_is_stored_with_its_defaults_and_overlaps_are_allowed(): void
    {
        $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-03-05 14:00:00-06', '2030-03-05 16:00:00-06');
        $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-03-05 15:00:00-06', '2030-03-05 17:00:00-06');

        $this->assertSame(2, DB::table('collaborator_absences')->count());
        $this->assertTrue(Str::isUuid((string) DB::table('collaborator_absences')->value('id'), 7));
    }

    /** An absence that ends when it starts, or before, is refused. */
    public function test_database_rejects_an_empty_or_inverted_range(): void
    {
        foreach ([['2030-03-05 14:00:00-06', '2030-03-05 14:00:00-06'], ['2030-03-05 16:00:00-06', '2030-03-05 14:00:00-06']] as [$from, $to]) {
            try {
                DB::transaction(fn () => $this->insertAbsence($this->puraNavaja, $this->daniela, $from, $to));
                $this->fail('A range that does not end after it starts was accepted.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
                $this->assertStringContainsString('collaborator_absences_range_check', $exception->getMessage());
            }
        }
    }

    /** An absence cannot point to a collaborator of another barbershop: the foreign key carries the barbershop (MT-04). */
    public function test_database_rejects_a_collaborator_of_another_barbershop(): void
    {
        try {
            DB::transaction(fn () => $this->insertAbsence($this->puraNavaja, $this->mauricio, '2030-03-05 14:00:00-06', '2030-03-05 16:00:00-06'));
            $this->fail('An absence for a collaborator of another barbershop was accepted.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    /** The reason takes 200 characters and no more. */
    public function test_reason_is_limited_to_two_hundred_characters(): void
    {
        $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-03-05 14:00:00-06', '2030-03-05 16:00:00-06', str_repeat('a', 200));

        $this->expectException(QueryException::class);
        DB::transaction(fn () => $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-03-06 14:00:00-06', '2030-03-06 16:00:00-06', str_repeat('a', 201)));
    }

    /** Deleting the collaborator or the barbershop deletes their absences. */
    public function test_absences_are_deleted_with_their_collaborator(): void
    {
        $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-03-05 14:00:00-06', '2030-03-05 16:00:00-06');

        DB::table('collaborators')->where('id', $this->daniela)->delete();

        $this->assertSame(0, DB::connection('pgsql_admin')->table('collaborator_absences')->count());
    }

    /** A barbershop only sees its own absences, and without a barbershop none is visible (MT-06). */
    public function test_a_barbershop_only_sees_its_own_absences(): void
    {
        $own = $this->insertAbsence($this->puraNavaja, $this->daniela, '2030-03-05 14:00:00-06', '2030-03-05 16:00:00-06');
        $this->scopeQueriesTo($this->fadeLab);
        $other = $this->insertAbsence($this->fadeLab, $this->mauricio, '2030-03-05 14:00:00-06', '2030-03-05 16:00:00-06');

        $this->assertSame([$other], DB::table('collaborator_absences')->pluck('id')->all());

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$own], DB::table('collaborator_absences')->pluck('id')->all());

        $this->scopeQueriesTo(null);
        $this->assertSame(0, DB::table('collaborator_absences')->count());
    }

    /** Writing an absence of another barbershop is refused by the policy. */
    public function test_policy_rejects_inserting_an_absence_for_another_barbershop(): void
    {
        try {
            DB::transaction(fn () => $this->insertAbsence($this->fadeLab, $this->mauricio, '2030-03-05 14:00:00-06', '2030-03-05 16:00:00-06'));
            $this->fail('An absence of another barbershop was inserted.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    /** The only policy of the table is enabled and forced, so it binds the owner of the table too. */
    public function test_table_has_its_isolation_policy_enabled_and_forced(): void
    {
        $security = DB::selectOne("select relrowsecurity, relforcerowsecurity from pg_class where oid = 'collaborator_absences'::regclass");
        $policies = DB::select("select policyname from pg_policies where schemaname = 'public' and tablename = 'collaborator_absences'");

        $this->assertTrue($security->relrowsecurity);
        $this->assertTrue($security->relforcerowsecurity);
        $this->assertSame(['collaborator_absences_barbershop_isolation'], array_column($policies, 'policyname'));
    }

    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** A collaborator with its account, inserted as the barbershop sees it. */
    private function insertCollaborator(string $barbershopId): string
    {
        $user = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $user, 'name' => 'Persona', 'email' => "{$user}@barberhub.test", 'password' => 'hash']);
        $this->scopeQueriesTo($barbershopId);
        $id = (string) Str::uuid7();
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $user]);

        return $id;
    }

    private function insertAbsence(string $barbershopId, string $collaboratorId, string $from, string $to, ?string $reason = null): string
    {
        $id = (string) Str::uuid7();
        DB::table('collaborator_absences')->insert([
            'id' => $id, 'barbershop_id' => $barbershopId, 'collaborator_id' => $collaboratorId, 'starts_at' => $from, 'ends_at' => $to, 'reason' => $reason,
        ]);

        return $id;
    }
}
