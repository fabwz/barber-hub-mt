<?php

declare(strict_types=1);

namespace Src\Catalog\RentInstallments\Application\Contracts;

use Closure;

/** Runs an operation as one barbershop, in its transaction, for a process that has no session: the daily generation and the registration. */
interface BarbershopScope
{
    /**
     * Run the operation with only the rows of the barbershop within reach.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(string $barbershopId, Closure $operation): mixed;
}
