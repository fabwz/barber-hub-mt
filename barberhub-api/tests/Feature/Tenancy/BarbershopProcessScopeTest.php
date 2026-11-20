<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\TestCase;

/**
 * The scope of a process without a request, such as a job of the queue (MT-02, MT-03): inside it the barbershop of the job is the context and
 * the only one its rows reach; outside it, or after a failure, the context has none, so the next job of the worker inherits nothing.
 */
final class BarbershopProcessScopeTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;

    private BarbershopProcessScope $scope;

    /** Two barbershops with their services. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo(null);
        $this->scope = $this->app->make(BarbershopProcessScope::class);
    }

    /** Inside the scope the context answers the barbershop of the job, and the database lets its transaction reach only its rows. */
    public function test_inside_the_scope_the_context_and_the_rows_are_the_barbershops(): void
    {
        $seen = $this->scope->run($this->puraNavaja, fn (): array => [
            $this->app->make(BarbershopContext::class)->current()->value,
            DB::table('services')->pluck('id')->all(),
        ]);

        $this->assertSame([$this->puraNavaja, [$this->degradado]], $seen);
    }

    /** The operation gives back what it returns, and another barbershop reaches the rows of the other one only. */
    public function test_each_barbershop_reaches_its_own_rows(): void
    {
        $this->assertSame([$this->corte], $this->scope->run($this->fadeLab, fn (): array => DB::table('services')->pluck('id')->all()));
    }

    /** Once the scope ends there is no barbershop in the context. The database forgets it when its outermost transaction ends, which here is the one of the test. */
    public function test_after_the_scope_there_is_no_barbershop(): void
    {
        $this->scope->run($this->puraNavaja, fn (): bool => true);

        $this->expectException(AuthenticationException::class);

        $this->app->make(BarbershopContext::class)->current();
    }

    /** A failure of the operation still clears the barbershop, because the worker goes on with the next job. */
    public function test_a_failure_still_clears_the_barbershop(): void
    {
        $failure = null;

        try {
            $this->scope->run($this->puraNavaja, fn () => throw new RuntimeException('The job failed.'));
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure, 'The failure was swallowed.');
        $this->expectException(AuthenticationException::class);

        $this->app->make(BarbershopContext::class)->current();
    }
}
