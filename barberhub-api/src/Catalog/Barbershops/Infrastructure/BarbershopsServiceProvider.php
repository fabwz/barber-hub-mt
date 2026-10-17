<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure;

use Illuminate\Support\ServiceProvider;

final class BarbershopsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [];
}
