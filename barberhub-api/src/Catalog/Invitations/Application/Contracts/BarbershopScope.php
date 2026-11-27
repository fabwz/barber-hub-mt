<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\Contracts;

use Closure;

/** Runs an operation as one barbershop when there is no person to take it from: the barbershop of an invitation found by its link. */
interface BarbershopScope
{
    /**
     * Run the operation with that barbershop as the current one and forget it afterwards, even if the operation fails.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(string $barbershopId, Closure $operation): mixed;
}
