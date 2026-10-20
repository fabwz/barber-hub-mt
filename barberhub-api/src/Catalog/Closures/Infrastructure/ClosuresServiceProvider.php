<?php

declare(strict_types=1);

namespace Src\Catalog\Closures\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Catalog\Closures\Domain\Contracts\ClosureRepository;
use Src\Catalog\Closures\Infrastructure\Persistence\Repositories\EloquentClosureRepository;

final class ClosuresServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ClosureRepository::class => EloquentClosureRepository::class,
    ];
}
