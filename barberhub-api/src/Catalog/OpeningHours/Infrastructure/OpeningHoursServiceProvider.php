<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure;

use Illuminate\Support\ServiceProvider;

final class OpeningHoursServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [];
}
