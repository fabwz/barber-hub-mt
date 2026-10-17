<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure;

use DateTimeZone;
use Illuminate\Support\ServiceProvider;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Infrastructure\Persistence\DatabaseTransactionRunner;

final class SharedServiceProvider extends ServiceProvider
{
    /** Bind the shared ports to their adapters. */
    public function register(): void
    {
        $this->app->bind(Clock::class, fn (): Clock => new SystemClock(new DateTimeZone(config('app.timezone'))));
        $this->app->bind(TransactionRunner::class, DatabaseTransactionRunner::class);
    }
}
