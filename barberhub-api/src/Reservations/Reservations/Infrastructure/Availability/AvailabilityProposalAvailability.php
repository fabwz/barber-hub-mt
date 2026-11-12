<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Availability;

use DateTimeImmutable;
use Src\Availability\Slots\Application\UseCases\ListAlternativeSlots;
use Src\Availability\Slots\Application\UseCases\ListAvailableSlots;
use Src\Availability\Slots\Domain\ValueObjects\Slot;
use Src\Reservations\Reservations\Application\Contracts\ProposalAvailability;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class AvailabilityProposalAvailability implements ProposalAvailability
{
    /** Receive the Availability use cases that list the free spaces of a day and the closest alternatives. */
    public function __construct(
        private ListAvailableSlots $available,
        private ListAlternativeSlots $alternatives,
    ) {}

    /**
     * The free spaces of a day through the Availability module, the same ones the customer sees.
     *
     * @return list<SlotAlternative>
     */
    public function onDay(string $serviceId, ?string $collaboratorId, string $date, DateTimeImmutable $now): array
    {
        return array_map($this->toSpace(...), $this->available->execute($serviceId, $date, $collaboratorId, $now));
    }

    /**
     * Up to 3 free spaces of the same day and service, the closest to the requested start, through the alternatives of the booking.
     *
     * @return list<SlotAlternative>
     */
    public function closest(string $serviceId, ?string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now): array
    {
        return array_map($this->toSpace(...), $this->alternatives->execute($serviceId, $collaboratorId, $requested, $now));
    }

    /** A slot of the Availability module as a space of this module. */
    private function toSpace(Slot $slot): SlotAlternative
    {
        return new SlotAlternative(new CollaboratorId($slot->collaboratorId->value), TimeInterval::between($slot->start, $slot->end));
    }
}
