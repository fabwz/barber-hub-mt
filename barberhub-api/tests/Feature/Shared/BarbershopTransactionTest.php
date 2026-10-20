<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Tests\TestCase;

final class BarbershopTransactionTest extends TestCase
{
    private const string BARBERSHOP_ID = '0192f0c4-0000-7000-8000-0000000000aa';

    private const string OTHER_ID = '0192f0c4-0000-7000-8000-0000000000bb';

    /** The tests run as the application role, the same one the requests use. */
    public function test_queries_run_as_the_application_role(): void
    {
        $this->assertSame(config('database.connections.pgsql.username'), DB::scalar('select current_user'));
    }

    /** The operation runs in one transaction with the barbershop set, and its result is returned. */
    public function test_runs_the_operation_in_a_transaction_scoped_to_the_barbershop(): void
    {
        $result = (new BarbershopTransaction)->run(self::BARBERSHOP_ID, fn (): array => [
            'barbershop' => $this->setting(),
            'levels' => DB::transactionLevel(),
        ]);

        $this->assertSame(['barbershop' => self::BARBERSHOP_ID, 'levels' => 1], $result);
    }

    /** Null sets the empty value, so no row of a barbershop is reachable. */
    public function test_null_reaches_no_barbershop(): void
    {
        $this->assertSame('', (new BarbershopTransaction)->run(null, fn (): mixed => $this->setting()));
    }

    /** The barbershop dies with the transaction, so a reused connection does not inherit it. */
    public function test_barbershop_ends_with_the_transaction(): void
    {
        (new BarbershopTransaction)->run(self::BARBERSHOP_ID, fn () => null);

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('', $this->setting());
    }

    /** A failure rolls the transaction back and the barbershop is gone as well. */
    public function test_failure_ends_the_transaction_and_the_barbershop(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            (new BarbershopTransaction)->run(self::BARBERSHOP_ID, function (): void {
                throw new RuntimeException('fails');
            });
        } finally {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('', $this->setting());
        }
    }

    /** Inside another transaction it is a savepoint, as the use cases of a request are, and the barbershop stays that of the request. */
    public function test_nested_transactions_are_savepoints_within_the_one_of_the_request(): void
    {
        $levels = (new BarbershopTransaction)->run(self::BARBERSHOP_ID, fn (): array => [
            DB::transaction(fn (): int => DB::transactionLevel()),
            $this->setting(),
        ]);

        $this->assertSame([2, self::BARBERSHOP_ID], $levels);
    }

    /** A second operation does not see the barbershop of the first one. */
    public function test_consecutive_operations_do_not_share_the_barbershop(): void
    {
        $transaction = new BarbershopTransaction;

        $transaction->run(self::BARBERSHOP_ID, fn () => null);

        $this->assertSame(self::OTHER_ID, $transaction->run(self::OTHER_ID, fn (): mixed => $this->setting()));
        $this->assertSame('', $transaction->run(null, fn (): mixed => $this->setting()));
    }

    /** Barbershop of the open transaction. */
    private function setting(): mixed
    {
        return DB::scalar("select current_setting('app.barbershop_id', true)");
    }
}
