<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure;

use DateTimeZone;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\ServiceProvider;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\BarbershopFileStorage;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\Contracts\DatabaseHealth;
use Src\Shared\Domain\Contracts\QueueHealth;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Infrastructure\Health\LaravelQueueHealth;
use Src\Shared\Infrastructure\Health\PostgresDatabaseHealth;
use Src\Shared\Infrastructure\OpenApi\ProblemResponsesTransformer;
use Src\Shared\Infrastructure\OpenApi\RelativeServerTransformer;
use Src\Shared\Infrastructure\Persistence\DatabaseTransactionRunner;
use Src\Shared\Infrastructure\Storage\FilesystemBarbershopFileStorage;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;

final class SharedServiceProvider extends ServiceProvider
{
    /** Bind the shared ports to their adapters. */
    public function register(): void
    {
        $this->app->bind(BarbershopContext::class, RequestBarbershopContext::class);
        $this->app->bind(BarbershopFileStorage::class, FilesystemBarbershopFileStorage::class);
        $this->app->bind(Clock::class, fn (): Clock => new SystemClock(new DateTimeZone(config('app.timezone'))));
        $this->app->bind(TransactionRunner::class, DatabaseTransactionRunner::class);
        $this->app->bind(DatabaseHealth::class, PostgresDatabaseHealth::class);
        $this->app->bind(QueueHealth::class, LaravelQueueHealth::class);
    }

    /** Adjust the generated API contract. */
    public function boot(): void
    {
        Scramble::configure()->withDocumentTransformers([
            ProblemResponsesTransformer::class,
            RelativeServerTransformer::class,
        ]);
    }
}
