<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

interface DomainEvents
{
    /**
     * Announce a fact of the domain, such as a space that was freed, so the contexts that care react to it in their own listeners
     * without the emitter knowing them. The event is a plain object of primitive values: it travels through a queue and carries the
     * barbershop it belongs to, because the process that handles it has no request to take it from.
     */
    public function publish(object $event): void;
}
