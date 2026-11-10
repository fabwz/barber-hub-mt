<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\Contracts;

use Closure;

/**
 * Runs an operation as the system inside one barbershop, in one transaction, for what has no session (the notices of the
 * gateways). The barbershop is the one found from the payment, never one the sender names; the rest of the processing runs
 * under the usual row-level security of that barbershop.
 */
interface BarbershopScope
{
    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(string $barbershopId, Closure $operation): mixed;
}
