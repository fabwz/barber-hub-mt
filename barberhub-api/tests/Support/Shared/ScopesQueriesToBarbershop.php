<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;

trait ScopesQueriesToBarbershop
{
    /** Make the next queries of the test reach only the rows of a barbershop, until something scopes them again. */
    protected function scopeQueriesTo(?string $barbershopId): void
    {
        $this->app->make(BarbershopTransaction::class)->scope($barbershopId);
    }
}
