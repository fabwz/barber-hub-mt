<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Users;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Identity\Users\Infrastructure\Persistence\Repositories\EloquentPasswordResetTokens;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class EloquentPasswordResetTokensTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private EloquentPasswordResetTokens $tokens;

    private UserId $ana;

    private DateTimeImmutable $now;

    /** Ana has an account, and the clock stands at a known instant. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = new EloquentPasswordResetTokens;
        $this->now = new DateTimeImmutable('2030-03-01 08:00:00-06:00');
        $this->ana = new UserId((string) UserModel::query()->create(['name' => 'Ana Mora', 'email' => 'ana@barberhub.test', 'password' => Hash::make('Clave#segura1')])->id);
    }

    /** A link is used once: the first consume answers the account and deletes the row, the second finds nothing. */
    public function test_a_link_is_consumed_once(): void
    {
        $this->tokens->replace($this->ana, $this->hash('a'), $this->now->modify('+60 minutes'));

        $this->assertEquals($this->ana, $this->tokens->consume($this->hash('a'), $this->now));
        $this->assertNull($this->tokens->consume($this->hash('a'), $this->now));
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /** A link is valid until its instant, and not at it. */
    public function test_a_link_is_not_valid_at_its_instant(): void
    {
        $this->tokens->replace($this->ana, $this->hash('a'), $this->now->modify('+60 minutes'));

        $this->assertNull($this->tokens->consume($this->hash('a'), $this->now->modify('+60 minutes')));
        $this->assertNull($this->tokens->consume($this->hash('a'), $this->now->modify('+61 minutes')));
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->assertEquals($this->ana, $this->tokens->consume($this->hash('a'), $this->now->modify('+59 minutes 59 seconds')));
    }

    /** A new link replaces the pending one of the same account. */
    public function test_a_new_link_replaces_the_previous_one(): void
    {
        $this->tokens->replace($this->ana, $this->hash('a'), $this->now->modify('+60 minutes'));
        $this->tokens->replace($this->ana, $this->hash('b'), $this->now->modify('+60 minutes'));

        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->assertNull($this->tokens->consume($this->hash('a'), $this->now));
        $this->assertEquals($this->ana, $this->tokens->consume($this->hash('b'), $this->now));
    }

    /** An unknown hash finds nothing and deletes nothing. */
    public function test_an_unknown_hash_finds_nothing(): void
    {
        $this->tokens->replace($this->ana, $this->hash('a'), $this->now->modify('+60 minutes'));

        $this->assertNull($this->tokens->consume($this->hash('zzz'), $this->now));
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    /** The row goes with the account. */
    public function test_the_link_is_deleted_with_the_account(): void
    {
        $this->tokens->replace($this->ana, $this->hash('a'), $this->now->modify('+60 minutes'));

        UserModel::query()->whereKey($this->ana->value)->delete();

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    /** The table accepts only a SHA-256 hash in hexadecimal, so a plain token cannot be stored by mistake. */
    public function test_the_table_refuses_what_is_not_a_hash(): void
    {
        $this->expectException(QueryException::class);

        DB::table('password_reset_tokens')->insert(['user_id' => $this->ana->value, 'token_hash' => 'not-a-hash', 'expires_at' => $this->now]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
