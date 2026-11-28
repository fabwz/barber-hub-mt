<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Tenancy;

use Closure;
use Src\Catalog\Barbershops\Application\Contracts\BarbershopScope;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Runs the technical record of a registration as the new barbershop. The route of the platform has no `{barberia}`, so the barbershop the
 * operator just created is not in the request: it comes from the registration itself, never from the sender.
 */
final readonly class ProcessBarbershopScope implements BarbershopScope
{
    /** Receive the scope of the processes without a barbershop in the request. */
    public function __construct(private BarbershopProcessScope $scope) {}

    public function run(string $barbershopId, Closure $operation): mixed
    {
        return $this->scope->run($barbershopId, $operation);
    }
}
