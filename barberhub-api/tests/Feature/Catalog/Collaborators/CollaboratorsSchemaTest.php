<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Collaborators;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The integrity of the collaborator tables lives in PostgreSQL too, and no relation crosses barbershops (ADR 0009, MT-04). */
final class CollaboratorsSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private string $puraNavaja;

    private string $fadeLab;

    private string $kevin;

    /** Two barbershops and a collaborator of the first one; the queries of the test reach the first one. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->puraNavaja = $this->insertBarbershop('pura-navaja');
        $this->fadeLab = $this->insertBarbershop('fade-lab');
        $this->scopeQueriesTo($this->puraNavaja);
        $this->kevin = $this->insertCollaborator($this->puraNavaja, 'kevin@barberhub.test');
    }

    /** The tables have the columns of the data model and no others; there is no table of absences. */
    public function test_tables_have_only_the_columns_of_this_issue(): void
    {
        $schema = Schema::connection('pgsql_admin');

        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'user_id', 'is_active', 'created_at', 'updated_at'], $schema->getColumnListing('collaborators'));
        $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'collaborator_id', 'service_id'], $schema->getColumnListing('collaborator_services'));

        foreach (['collaborator_shifts', 'collaborator_breaks'] as $table) {
            $this->assertEqualsCanonicalizing(['id', 'barbershop_id', 'collaborator_id', 'day_of_week', 'starts_at', 'ends_at', 'created_at', 'updated_at'], $schema->getColumnListing($table));
        }

        $this->assertFalse($schema->hasTable('collaborator_absences'));
    }

    /** A collaborator is active by default and gets a version 7 identifier and timestamps. */
    public function test_application_role_stores_an_active_collaborator_with_its_defaults(): void
    {
        $collaborator = DB::table('collaborators')->sole();

        $this->assertTrue(Str::isUuid($collaborator->id, 7));
        $this->assertTrue($collaborator->is_active);
        $this->assertNotNull($collaborator->created_at);
    }

    /** A person has one profile per barbershop, and can have one in another barbershop. */
    public function test_one_profile_per_person_and_barbershop(): void
    {
        $userId = DB::table('collaborators')->sole()->user_id;
        $this->scopeQueriesTo($this->fadeLab);

        DB::table('collaborators')->insert(['barbershop_id' => $this->fadeLab, 'user_id' => $userId]);
        $this->assertSame(1, DB::table('collaborators')->count());

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => DB::table('collaborators')->insert(['barbershop_id' => $this->fadeLab, 'user_id' => $userId]));
    }

    /** A service can be enabled for a collaborator once, and only a service of the same barbershop. */
    public function test_services_must_belong_to_the_barbershop_of_the_collaborator(): void
    {
        $haircut = $this->insertService($this->puraNavaja);
        DB::table('collaborator_services')->insert(['barbershop_id' => $this->puraNavaja, 'collaborator_id' => $this->kevin, 'service_id' => $haircut]);
        $this->scopeQueriesTo($this->fadeLab);
        $foreign = $this->insertService($this->fadeLab);
        $this->scopeQueriesTo($this->puraNavaja);

        try {
            DB::transaction(fn () => DB::table('collaborator_services')->insert(['barbershop_id' => $this->puraNavaja, 'collaborator_id' => $this->kevin, 'service_id' => $foreign]));
            $this->fail('A service of another barbershop was enabled.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(fn () => DB::table('collaborator_services')->insert(['barbershop_id' => $this->puraNavaja, 'collaborator_id' => $this->kevin, 'service_id' => $haircut]));
    }

    /** A shift or a break of a collaborator cannot be written in another barbershop, even by the application role. */
    #[DataProvider('scheduleTables')]
    public function test_schedule_rows_must_belong_to_the_barbershop_of_the_collaborator(string $table): void
    {
        $this->scopeQueriesTo($this->fadeLab);

        try {
            DB::transaction(fn () => DB::table($table)->insert($this->row($this->fadeLab, $this->kevin, 1, '09:00', '12:00')));
            $this->fail('A row pointed at a collaborator of another barbershop.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->getCode());
        }
    }

    /** The days go from Monday to Sunday and the end is after the start. */
    #[DataProvider('scheduleTables')]
    public function test_day_and_hours_are_checked(string $table): void
    {
        foreach ([[0, '09:00', '12:00'], [8, '09:00', '12:00'], [1, '12:00', '12:00'], [1, '12:00', '09:00']] as [$day, $starts, $ends]) {
            try {
                DB::transaction(fn () => DB::table($table)->insert($this->row($this->puraNavaja, $this->kevin, $day, $starts, $ends)));
                $this->fail("Accepted day {$day} from {$starts} to {$ends}.");
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }

        DB::table($table)->insert($this->row($this->puraNavaja, $this->kevin, 7, '09:00', '12:00'));
        $this->assertSame(1, DB::table($table)->count());
    }

    /** Two entries of a collaborator that share a minute of a day are rejected; touching ones, other days and other collaborators are not (ADR 0009). */
    #[DataProvider('scheduleTables')]
    public function test_overlapping_entries_are_rejected_by_the_exclusion(string $table): void
    {
        $other = $this->insertCollaborator($this->puraNavaja, 'ana@barberhub.test');
        DB::table($table)->insert($this->row($this->puraNavaja, $this->kevin, 1, '09:00', '12:00'));

        DB::table($table)->insert($this->row($this->puraNavaja, $this->kevin, 1, '12:00', '15:00'));
        DB::table($table)->insert($this->row($this->puraNavaja, $this->kevin, 2, '09:00', '12:00'));
        DB::table($table)->insert($this->row($this->puraNavaja, $other, 1, '09:00', '12:00'));
        $this->assertSame(4, DB::table($table)->count());

        foreach ([['11:59', '14:00'], ['08:00', '09:01'], ['10:00', '11:00']] as [$starts, $ends]) {
            try {
                DB::transaction(fn () => DB::table($table)->insert($this->row($this->puraNavaja, $this->kevin, 1, $starts, $ends)));
                $this->fail("Accepted {$starts}-{$ends} over 09:00-12:00.");
            } catch (QueryException $exception) {
                $this->assertSame('23P01', $exception->getCode());
            }
        }
    }

    /** Removing a collaborator removes their services, shifts and breaks. */
    public function test_children_are_removed_with_the_collaborator(): void
    {
        DB::table('collaborator_shifts')->insert($this->row($this->puraNavaja, $this->kevin, 1, '09:00', '12:00'));
        DB::table('collaborator_breaks')->insert($this->row($this->puraNavaja, $this->kevin, 1, '10:00', '10:30'));
        DB::table('collaborator_services')->insert(['barbershop_id' => $this->puraNavaja, 'collaborator_id' => $this->kevin, 'service_id' => $this->insertService($this->puraNavaja)]);

        DB::table('collaborators')->where('id', $this->kevin)->delete();

        $this->assertSame([0, 0, 0], [DB::table('collaborator_shifts')->count(), DB::table('collaborator_breaks')->count(), DB::table('collaborator_services')->count()]);
    }

    /**
     * The tables that hold weekly entries.
     *
     * @return array<string, array{string}>
     */
    public static function scheduleTables(): array
    {
        return ['shifts' => ['collaborator_shifts'], 'breaks' => ['collaborator_breaks']];
    }

    /**
     * A row of a shift or a break.
     *
     * @return array<string, mixed>
     */
    private function row(string $barbershopId, string $collaboratorId, int $day, string $starts, string $ends): array
    {
        return ['barbershop_id' => $barbershopId, 'collaborator_id' => $collaboratorId, 'day_of_week' => $day, 'starts_at' => $starts, 'ends_at' => $ends];
    }

    /** Insert a barbershop straight into the table and return its identifier. */
    private function insertBarbershop(string $link): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => $link, 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $link]);

        return $id;
    }

    /** Insert an account and its profile in the barbershop the queries reach, and return the identifier of the profile. */
    private function insertCollaborator(string $barbershopId, string $email): string
    {
        $userId = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $userId, 'name' => $email, 'email' => $email, 'password' => 'hash']);
        DB::table('collaborators')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'user_id' => $userId]);

        return $id;
    }

    /** Insert a service in the barbershop the queries reach, and return its identifier. */
    private function insertService(string $barbershopId): string
    {
        $id = (string) Str::uuid7();
        DB::table('services')->insert(['id' => $id, 'barbershop_id' => $barbershopId, 'name' => 'Corte', 'duration_minutes' => 30, 'price' => 7000]);

        return $id;
    }
}
