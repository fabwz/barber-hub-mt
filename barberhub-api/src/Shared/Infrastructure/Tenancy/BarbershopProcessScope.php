<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Tenancy;

use Closure;

/**
 * What the `barbershop` middleware does for a request with a session, for a process that has no request: a job of the queue or a listener
 * that a worker runs. It fixes the barbershop where `BarbershopContext` reads it and runs the operation in the transaction of that barbershop,
 * so repositories and row policies act as always. It checks no membership because there is no person: the barbershop comes from the payload
 * of the job, which was written from the context already verified when the job was left in the queue, never from a sender. It is the same
 * technique the notices of the gateways use (#65), for the processes of the queue; what a process that must go through every barbershop needs
 * is still undecided (docs/database.md, "Pendientes").
 */
final readonly class BarbershopProcessScope
{
    /** Receive the transaction that reaches the rows of one barbershop. */
    public function __construct(private BarbershopTransaction $transaction) {}

    /**
     * Run the operation as the barbershop and forget it afterwards, even if the operation fails, because a worker serves many jobs one
     * after another and the next one must not inherit the barbershop of this one.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
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
