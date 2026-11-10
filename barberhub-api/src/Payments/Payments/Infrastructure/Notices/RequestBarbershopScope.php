<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Notices;

use Closure;
use Src\Payments\Payments\Application\Contracts\BarbershopScope;
use Src\Shared\Infrastructure\Tenancy\BarbershopTransaction;
use Src\Shared\Infrastructure\Tenancy\RequestBarbershopContext;

/**
 * What the `barbershop` middleware does for a request with a session, for one without it: fixes the barbershop in the request,
 * so `BarbershopContext` answers it, and runs the operation in the transaction of that barbershop. It checks no membership because
 * there is no person; the barbershop was found from a payment, never from the sender.
 */
final readonly class RequestBarbershopScope implements BarbershopScope
{
    /** Receive the transaction that reaches the rows of one barbershop. */
    public function __construct(private BarbershopTransaction $transaction) {}

    public function run(string $barbershopId, Closure $operation): mixed
    {
        $attributes = request()->attributes;
        $attributes->set(RequestBarbershopContext::BARBERSHOP_ATTRIBUTE, $barbershopId);
        $attributes->set(RequestBarbershopContext::ROLE_ATTRIBUTE, null);

        try {
            return $this->transaction->run($barbershopId, $operation);
        } finally {
            $attributes->remove(RequestBarbershopContext::BARBERSHOP_ATTRIBUTE);
            $attributes->remove(RequestBarbershopContext::ROLE_ATTRIBUTE);
        }
    }
}
