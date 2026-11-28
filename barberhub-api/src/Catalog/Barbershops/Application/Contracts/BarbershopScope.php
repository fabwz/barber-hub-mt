<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

use Closure;

/** Runs an operation as one barbershop, in its transaction, when no request names it: the platform operator registers a barbershop that did not exist before. */
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
