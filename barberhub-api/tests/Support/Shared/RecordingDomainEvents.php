<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Src\Shared\Domain\Contracts\DomainEvents;

/** The events a use case published, in the order it published them, for the tests that run it without the framework. */
final class RecordingDomainEvents implements DomainEvents
{
    /** @var list<object> */
    private array $events = [];

    /** Keep the event. */
    public function publish(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * Every event published, in order.
     *
     * @return list<object>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * The events published of one class, in order.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, fn (object $event): bool => $event instanceof $class));
    }
}
