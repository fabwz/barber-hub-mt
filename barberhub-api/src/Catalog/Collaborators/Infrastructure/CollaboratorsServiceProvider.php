<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure;

use Illuminate\Support\ServiceProvider;

final class CollaboratorsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [];
}
