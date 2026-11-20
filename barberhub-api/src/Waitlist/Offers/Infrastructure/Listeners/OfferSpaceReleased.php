<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Src\Waitlist\Offers\Application\UseCases\OfferReleasedSpace;

/**
 * Reacts to a space that a reservation freed, by a cancellation, an expiry or a reschedule, and offers it to the first compatible person of the
 * line (CA-07, RF-31). It runs in a worker, after the transaction that freed the space commits, so the offer is made from what was really saved
 * and a failure of the line never undoes a cancellation. The workers retry it, and the use case offers the same space once however many times
 * it runs (RNF-09).
 */
final class OfferSpaceReleased implements ShouldQueue
{
    /** Leave the listener in the queue only after the transaction that freed the space commits. */
    public bool $afterCommit = true;

    /** Attempts before the job is recorded as failed and stays visible in the monitoring (RNF-09). */
    public int $tries = 3;

    /** @var list<int> seconds to wait before the second and third attempts */
    public array $backoff = [5, 15];

    /** Receive the scope of a process without a request, the use case that offers the space and the clock. */
    public function __construct(
        private readonly BarbershopProcessScope $scope,
        private readonly OfferReleasedSpace $offerSpace,
        private readonly Clock $clock,
    ) {}

    /** Offer the space as the barbershop that freed it, in its transaction, at the current instant. */
    public function handle(SpaceReleased $event): void
    {
        $this->scope->run($event->barbershopId, fn () => $this->offerSpace->execute($event->collaboratorId, $event->startsAt, $event->endsAt, $this->clock->now()));
    }
}
