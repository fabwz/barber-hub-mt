<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Availability;

use DateTimeImmutable;
use Src\Availability\Slots\Application\UseCases\AssignAnyAvailable;
use Src\Availability\Slots\Application\UseCases\ListAlternativeSlots;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Reservations\Reservations\Application\Contracts\AnyAvailableCollaborators;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class AvailabilityAnyAvailableCollaborators implements AnyAvailableCollaborators
{
    /** Receive the Availability use cases that assign "any available" and list the closest alternatives. */
    public function __construct(
        private AssignAnyAvailable $assignment,
        private ListAlternativeSlots $alternatives,
    ) {}

    /**
     * The collaborators who could take the space, through the Availability module.
     *
     * @return list<string>
     */
    public function candidates(string $serviceId, DateTimeImmutable $startsAt): array
    {
        return $this->assignment->candidates($serviceId, $startsAt);
    }

    /** The collaborator DI-04 assigns, through the Availability module. */
    public function assign(string $serviceId, DateTimeImmutable $startsAt, DateTimeImmutable $now): ?string
    {
        return $this->assignment->forStart($serviceId, $startsAt, $now);
    }

    /**
     * Up to 3 free spaces of any collaborator, the closest to the requested start, through the Availability module.
     *
     * @return list<SlotAlternative>
     */
    public function alternatives(string $serviceId, DateTimeImmutable $requested, DateTimeImmutable $now): array
    {
        return array_map(
            fn (Slot $slot): SlotAlternative => new SlotAlternative(
                new CollaboratorId($slot->collaboratorId->value),
                TimeInterval::between($slot->start, $slot->end),
            ),
            $this->alternatives->execute($serviceId, null, $requested, $now),
        );
    }
}
