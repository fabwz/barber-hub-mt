<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

/**
 * The technical log of what the system did (RNF-25): one entry per payment created, refunded or marked to give back. It is not the
 * record of sensitive actions (`AuditLog`). Every entry carries the correlation identifier of the execution.
 */
interface OperationLog
{
    /**
     * Write one entry. The context holds identifiers, enumerated values and amounts only: never contact details, secrets or
     * what a provider sent.
     *
     * @param  array<string, bool|int|string|null>  $context
     */
    public function record(string $event, array $context = []): void;
}
