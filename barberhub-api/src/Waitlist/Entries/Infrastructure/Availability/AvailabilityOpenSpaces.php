<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Infrastructure\Availability;

use DateTimeImmutable;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Entries\Application\Contracts\OpenSpaces;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\OpenSpace;

final readonly class AvailabilityOpenSpaces implements OpenSpaces
{
    /** Receive the Availability use case that lists the free spaces of a day. */
    public function __construct(private ListAvailableSlots $available) {}

    /**
     * The free spaces of the day through the Availability module, of one collaborator or of every one that offers the service,
     * already in order of start.
     *
     * @return list<OpenSpace>
     */
    public function on(string $serviceId, string $date, ?string $collaboratorId, DateTimeImmutable $now): array
    {
        return array_map(
            fn (Slot $slot): OpenSpace => new OpenSpace(
                new CollaboratorId($slot->collaboratorId->value),
                TimeInterval::between($slot->start, $slot->end),
            ),
            $this->available->execute($serviceId, $date, $collaboratorId, $now),
        );
    }
}
