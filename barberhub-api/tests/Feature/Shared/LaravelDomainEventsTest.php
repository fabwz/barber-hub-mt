<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Shared\Infrastructure\Events\LaravelDomainEvents;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/** The port that announces the facts of the domain reaches the listeners the modules register, and the queue has the tables its driver needs (RNF-09). */
final class LaravelDomainEventsTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** The port is bound to the adapter of the framework, so the use cases do not know the dispatcher. */
    public function test_the_port_is_bound_to_the_adapter(): void
    {
        $this->assertInstanceOf(LaravelDomainEvents::class, $this->app->make(DomainEvents::class));
    }

    /** A published event reaches the listeners of its class, with the values it carries, and none of another class. */
    public function test_a_published_event_reaches_the_listeners_of_its_class(): void
    {
        $heard = [];
        Event::listen(ProbeEvent::class, function (ProbeEvent $event) use (&$heard): void {
            $heard[] = $event->barbershopId;
        });
        Event::listen(OtherProbeEvent::class, function () use (&$heard): void {
            $heard[] = 'other';
        });

        $this->app->make(DomainEvents::class)->publish(new ProbeEvent('0192f3a0-0000-7000-8000-000000000001'));

        $this->assertSame(['0192f3a0-0000-7000-8000-000000000001'], $heard);
    }

    /** The tables of the database queue exist, so a job waits in the database and a failed one stays visible. */
    public function test_the_tables_of_the_queue_exist(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
    }
}

/** An event of the test. */
final readonly class ProbeEvent
{
    public function __construct(public string $barbershopId) {}
}

/** Another event of the test, which nobody should hear. */
final readonly class OtherProbeEvent {}
