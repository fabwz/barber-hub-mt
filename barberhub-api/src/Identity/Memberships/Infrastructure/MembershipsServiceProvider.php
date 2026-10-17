<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Infrastructure;

use Illuminate\Support\ServiceProvider;

final class MembershipsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [];
}
