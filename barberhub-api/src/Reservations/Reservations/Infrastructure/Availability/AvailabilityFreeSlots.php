<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Availability;

use DateTimeImmutable;
use Src\Availability\Slots\Application\UseCases\ListAlternativeSlots;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class AvailabilityFreeSlots implements FreeSlots
{
    /** Receive the Availability use cases that list the free spaces of a day and the closest alternatives. */
    public function __construct(
        private ListAvailableSlots $available,
        private ListAlternativeSlots $alternatives,
    ) {}

    /** Whether the day of the moment lists a free space of the collaborator that starts exactly then. */
    public function isFree(string $serviceId, string $collaboratorId, DateTimeImmutable $startsAt, DateTimeImmutable $now): bool
    {
        $slots = $this->available->execute($serviceId, CalendarDate::fromMoment($startsAt)->value, $collaboratorId, $now);

        return array_any($slots, fn (Slot $slot): bool => $slot->start->getTimestamp() === $startsAt->getTimestamp());
    }

    /**
     * Up to 3 free spaces of the same collaborator, service and day through the Availability module.
     *
     * @return list<SlotAlternative>
     */
    public function alternatives(string $serviceId, string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now): array
    {
        return array_map(
            fn (Slot $slot): SlotAlternative => new SlotAlternative(
                new CollaboratorId($slot->collaboratorId->value),
                TimeInterval::between($slot->start, $slot->end),
            ),
            $this->alternatives->execute($serviceId, $collaboratorId, $requested, $now),
        );
    }
}
