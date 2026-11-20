<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\CustomerBookings;
use Src\Waitlist\Offers\Application\Contracts\OfferDeadlines;
use Src\Waitlist\Offers\Application\Contracts\ServiceMinutes;
use Src\Waitlist\Offers\Application\Contracts\SpaceAvailability;
use Src\Waitlist\Offers\Application\Contracts\WaitlistSwitch;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;

/**
 * The ports of the offers that are not the line, for the tests that run the use cases without a framework: the switch, the catalog, the lock,
 * the availability, the reservations and the queue. The availability answers as the real one does for what concerns the offers: a space held by
 * a pending offer inside its time is not free (DI-05).
 */
final class OfferPorts implements CollaboratorLock, CustomerBookings, OfferDeadlines, ServiceMinutes, SpaceAvailability, WaitlistSwitch
{
    public bool $enabled = true;

    public bool $collaboratorExists = true;

    /** @var array<string, int> The minutes each service needs from the collaborator, cleaning included; a service that is not here is not offered. */
    public array $minutes = [];

    /** @var list<string> The services whose spaces are taken by something other than an offer. */
    public array $taken = [];

    /** @var list<string> The customers who have a reservation of their own in the space. */
    public array $busy = [];

    /** @var list<string> What happened, in order. */
    public array $calls = [];

    /** @var list<array{string, DateTimeImmutable}> The offer and the instant of each task left for its deadline. */
    public array $deadlines = [];

    /** Build the ports over the offers, which the availability reads to know what is held. */
    public function __construct(private readonly InMemoryOfferRepository $offers) {}

    /** Forget what happened so far. */
    public function forgetCalls(): void
    {
        $this->calls = [];
    }

    /** Whether the waitlist is on. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The minutes of the services the collaborator offers.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, int>
     */
    public function needed(string $collaboratorId, array $serviceIds): array
    {
        return array_intersect_key($this->minutes, array_flip($serviceIds));
    }

    /** Record the lock. */
    public function lock(string $collaboratorId): bool
    {
        $this->calls[] = 'lock';

        return $this->collaboratorExists;
    }

    /** Free unless something else took the space for the service, or a pending offer holds it (DI-05). */
    public function isFree(string $serviceId, string $collaboratorId, TimeInterval $space, DateTimeImmutable $now): bool
    {
        $this->calls[] = "free {$serviceId}";

        return ! in_array($serviceId, $this->taken, true) && ! $this->offers->holds(new CollaboratorId($collaboratorId), $space, $now);
    }

    /** Whether the customer has a reservation of their own in the interval. */
    public function hasOverlapping(string $customerMembershipId, TimeInterval $interval): bool
    {
        return in_array($customerMembershipId, $this->busy, true);
    }

    /** Keep the task. */
    public function expireAt(string $offerId, DateTimeImmutable $at): void
    {
        $this->deadlines[] = [$offerId, $at];
    }
}
