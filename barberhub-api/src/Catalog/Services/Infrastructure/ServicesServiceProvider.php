<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Catalog\Services\Application\Contracts\PublicServiceReader;
use Src\Catalog\Services\Domain\Contracts\ServiceRepository;
use Src\Catalog\Services\Infrastructure\Persistence\Queries\EloquentPublicServiceReader;
use Src\Catalog\Services\Infrastructure\Persistence\Repositories\EloquentServiceRepository;

final class ServicesServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ServiceRepository::class => EloquentServiceRepository::class,
        PublicServiceReader::class => EloquentPublicServiceReader::class,
    ];
}
