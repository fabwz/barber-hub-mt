<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Waitlist;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\UseCases\ListOfferedSpaces;

final readonly class OffersBlockedIntervals
{
    /** Receive the Offers use case that lists the spaces held by pending offers. */
    public function __construct(private ListOfferedSpaces $listOffered) {}

    /**
     * List the spaces held by the pending offers of some collaborators in a window through the Waitlist module (DI-05).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array
    {
        return $this->listOffered->execute($collaboratorIds, $window, $now);
    }
}
