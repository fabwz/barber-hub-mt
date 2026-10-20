<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Catalog\Barbershops\Application\Contracts\OwnerMemberships;
use Src\Catalog\Barbershops\Application\Contracts\PublicBarbershopReader;
use Src\Catalog\Barbershops\Domain\Contracts\BarbershopRepository;
use Src\Catalog\Barbershops\Infrastructure\Identity\IdentityOwnerMemberships;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Queries\EloquentPublicBarbershopReader;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Repositories\EloquentBarbershopRepository;

final class BarbershopsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        BarbershopRepository::class => EloquentBarbershopRepository::class,
        OwnerMemberships::class => IdentityOwnerMemberships::class,
        PublicBarbershopReader::class => EloquentPublicBarbershopReader::class,
    ];
}
