<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Infrastructure\Tenancy;

use Closure;
use Src\Catalog\RentInstallments\Application\Contracts\BarbershopScope;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Runs the generation of one barbershop as that barbershop, with the scope the queue workers use. The daily task has no session, but the
 * barbershop it works on comes from the list of barbershops (which has no row security), never from a request.
 */
final readonly class ProcessBarbershopScope implements BarbershopScope
{
    /** Receive the scope of the processes without a session. */
    public function __construct(private BarbershopProcessScope $scope) {}

    public function run(string $barbershopId, Closure $operation): mixed
    {
        return $this->scope->run($barbershopId, $operation);
    }
}
