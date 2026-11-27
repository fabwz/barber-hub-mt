<?php

declare(strict_types=1);

namespace Src\Reservations\AssistedClients\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Reservations\AssistedClients\Domain\Contracts\AssistedClientRepository;
use Src\Reservations\AssistedClients\Infrastructure\Persistence\Repositories\EloquentAssistedClientRepository;

final class AssistedClientsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AssistedClientRepository::class => EloquentAssistedClientRepository::class,
    ];
}
