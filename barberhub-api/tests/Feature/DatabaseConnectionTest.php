<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DatabaseConnectionTest extends TestCase
{
    public function test_default_connection_uses_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }

    public function test_database_accepts_queries(): void
    {
        $this->assertSame(1, DB::scalar('select 1'));
    }

    public function test_server_is_postgresql_18_or_newer(): void
    {
        $this->assertGreaterThanOrEqual(180000, (int) DB::scalar('show server_version_num'));
    }

    public function test_server_generates_uuid_v7(): void
    {
        $uuid = DB::scalar('select uuidv7()');

        $this->assertIsString($uuid);
        $this->assertTrue(Str::isUuid($uuid, 7));
    }
}
