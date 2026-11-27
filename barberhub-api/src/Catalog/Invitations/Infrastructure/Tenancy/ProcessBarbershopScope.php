<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Tenancy;

use Closure;
use Src\Catalog\Invitations\Application\Contracts\BarbershopScope;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Runs the accepting of an invitation as the barbershop of its link, with the scope the queue workers use: it fixes the barbershop where
 * `BarbershopContext` reads it and runs the operation in the transaction of that barbershop. It checks no membership because there is no
 * person yet; the barbershop was found from the link, never from the sender.
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
