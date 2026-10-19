<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class UserSchemaTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** The table has the columns of the registration and none that belong to later issues or to memberships. */
    public function test_table_has_only_the_columns_of_this_issue(): void
    {
        $columns = Schema::connection('pgsql_admin')->getColumnListing('users');

        $this->assertEqualsCanonicalizing([
            'id', 'name', 'email', 'password', 'phone', 'is_active',
            'terms_accepted_at', 'privacy_accepted_at', 'created_at', 'updated_at',
        ], $columns);
    }

    /** The application role stores an account that is active by default, with a version 7 identifier and timestamps. */
    public function test_application_role_stores_an_active_account(): void
    {
        $this->insertUser();

        $user = DB::table('users')->sole();

        $this->assertTrue(Str::isUuid($user->id, 7));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->created_at);
        $this->assertNotNull($user->updated_at);
    }

    /** The phone and the two acceptances accept nulls: the account of a collaborator is created by the owner. */
    public function test_phone_and_acceptances_are_nullable(): void
    {
        $this->insertUser();

        $user = DB::table('users')->sole();

        $this->assertNull($user->phone);
        $this->assertNull($user->terms_accepted_at);
        $this->assertNull($user->privacy_accepted_at);
    }

    /** Two accounts cannot share an email address. */
    public function test_database_rejects_a_repeated_email(): void
    {
        $this->insertUser();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertUser();
    }

    /** The name is required. */
    public function test_database_rejects_an_account_without_a_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert(['email' => 'ana@barberhub.test', 'password' => 'hash']);
    }

    /** Insert an account straight into the table. */
    private function insertUser(): void
    {
        DB::table('users')->insert([
            'name' => 'Ana Mora',
            'email' => 'ana@barberhub.test',
            'password' => 'hash',
        ]);
    }
}
