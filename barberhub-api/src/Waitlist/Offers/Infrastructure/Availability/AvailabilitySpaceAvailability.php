<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure\Availability;

use DateTimeImmutable;
use Src\Availability\Slots\Application\UseCases\IsSpaceFree;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\Contracts\SpaceAvailability;

final readonly class AvailabilitySpaceAvailability implements SpaceAvailability
{
    /** Receive the Availability use cases that list the free spaces of a day and that tell whether a whole space is free. */
    public function __construct(
        private ListAvailableSlots $available,
        private IsSpaceFree $spaceFree,
    ) {}

    /**
     * Whether the day lists a free space of the collaborator for the service that starts exactly when the space does, and nothing holds any
     * minute of the whole space, through the Availability module. The first is the calculation the booking uses; the second makes sure the
     * rest of the hole, beyond what the service needs, is still whole, because the offer holds all of it (DI-05).
     */
    public function isFree(string $serviceId, string $collaboratorId, TimeInterval $space, DateTimeImmutable $now): bool
    {
        $slots = $this->available->execute($serviceId, CalendarDate::fromMoment($space->start)->value, $collaboratorId, $now);

        return array_any($slots, fn (Slot $slot): bool => $slot->start->getTimestamp() === $space->start->getTimestamp())
            && $this->spaceFree->execute($collaboratorId, $space, $now);
    }
}
