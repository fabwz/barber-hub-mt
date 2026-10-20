<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Memberships;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class MembershipSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** The table has the columns of the data model. */
    public function test_table_has_the_columns_of_the_data_model(): void
    {
        $columns = Schema::connection('pgsql_admin')->getColumnListing('memberships');

        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'barbershop_id', 'role', 'is_active', 'created_at', 'updated_at'],
            $columns,
        );
    }

    /** A membership is active by default, with a version 7 identifier and timestamps. */
    public function test_membership_is_active_by_default(): void
    {
        $this->insertMembership($this->insertUser('ana@barberhub.test'), $this->insertBarbershop(), 'owner');

        $membership = DB::table('memberships')->sole();

        $this->assertTrue(Str::isUuid($membership->id, 7));
        $this->assertTrue($membership->is_active);
        $this->assertNotNull($membership->created_at);
        $this->assertNotNull($membership->updated_at);
    }

    /** A person has a single role in a barbershop (UP-07), but may have another role elsewhere. */
    public function test_person_has_a_single_role_per_barbershop(): void
    {
        $user = $this->insertUser('ana@barberhub.test');
        $barbershop = $this->insertBarbershop();
        $this->insertMembership($user, $barbershop, 'owner');

        $this->insertMembership($user, $this->insertBarbershop(), 'customer');
        $this->assertDatabaseCount('memberships', 2);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertMembership($user, $barbershop, 'customer');
    }

    /** The pair (id, barbershop_id) is a candidate key for the composite foreign keys of later tables. */
    public function test_id_and_barbershop_form_a_candidate_key(): void
    {
        $this->insertMembership($this->insertUser('ana@barberhub.test'), $this->insertBarbershop(), 'owner');

        $keys = DB::connection('pgsql_admin')->select(<<<'SQL'
            select array_agg(a.attname order by a.attname) as columns
            from pg_constraint c
            join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any (c.conkey)
            where c.conrelid = 'memberships'::regclass and c.contype in ('p', 'u')
            group by c.oid
            SQL);

        $columns = array_column($keys, 'columns');

        $this->assertContains('{barbershop_id,id}', $columns);
        $this->assertContains('{barbershop_id,user_id}', $columns);
    }

    /** Only the three roles of UP-01 are stored. */
    public function test_database_rejects_an_unknown_role(): void
    {
        $this->expectException(QueryException::class);

        $this->insertMembership($this->insertUser('ana@barberhub.test'), $this->insertBarbershop(), 'admin');
    }

    /** The user and the barbershop must exist. */
    public function test_database_rejects_a_membership_without_user_or_barbershop(): void
    {
        $user = $this->insertUser('ana@barberhub.test');
        $barbershop = $this->insertBarbershop();

        foreach ([[$user, (string) Str::uuid7()], [(string) Str::uuid7(), $barbershop]] as [$userId, $barbershopId]) {
            try {
                DB::transaction(fn () => $this->insertMembership($userId, $barbershopId, 'owner'));
                $this->fail('A membership without user or barbershop was accepted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('memberships', 0);
            }
        }
    }

    /** Deleting a barbershop deletes its memberships and keeps the account. */
    public function test_deleting_a_barbershop_deletes_its_memberships(): void
    {
        $user = $this->insertUser('ana@barberhub.test');
        $barbershop = $this->insertBarbershop();
        $this->insertMembership($user, $barbershop, 'owner');

        DB::table('barbershops')->where('id', $barbershop)->delete();

        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('users', 1);
    }

    /** Deleting an account deletes its memberships and keeps the barbershop. */
    public function test_deleting_a_user_deletes_its_memberships(): void
    {
        $user = $this->insertUser('ana@barberhub.test');
        $this->insertMembership($user, $this->insertBarbershop(), 'owner');

        DB::table('users')->where('id', $user)->delete();

        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('barbershops', 1);
    }

    /** Insert an account and return its identifier. */
    private function insertUser(string $email): string
    {
        $id = (string) Str::uuid7();
        DB::table('users')->insert(['id' => $id, 'name' => 'Ana Mora', 'email' => $email, 'password' => 'hash']);

        return $id;
    }

    /** Insert a barbershop and return its identifier. */
    private function insertBarbershop(): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert(['id' => $id, 'name' => 'Pura Navaja', 'city' => 'San Carlos', 'phone' => '2460-0000', 'public_link' => $id]);

        return $id;
    }

    /** Insert a membership straight into the table. */
    private function insertMembership(string $userId, string $barbershopId, string $role): void
    {
        DB::table('memberships')->insert(['user_id' => $userId, 'barbershop_id' => $barbershopId, 'role' => $role]);
    }
}
