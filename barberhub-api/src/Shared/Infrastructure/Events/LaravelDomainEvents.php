<?php

declare(strict_types=1);

namespace Src\Shared\Infrastructure\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Src\Shared\Domain\Contracts\DomainEvents;

final readonly class LaravelDomainEvents implements DomainEvents
{
    /** Receive the dispatcher of the framework, whose listeners the modules register in their service providers. */
    public function __construct(private Dispatcher $dispatcher) {}

    /** Hand the event to the listeners registered for its class; a queued listener is left in the queue until the open transaction commits. */
    public function publish(object $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
