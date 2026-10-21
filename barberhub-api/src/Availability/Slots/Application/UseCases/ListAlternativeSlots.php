<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\UseCases;

use DateTimeImmutable;
use Src\Availability\Slots\Domain\Services\SlotCalculator;
use Src\Availability\Slots\Domain\ValueObjects\CalendarDate;
use Src\Availability\Slots\Domain\ValueObjects\Slot;

final readonly class ListAlternativeSlots
{
    public const int LIMIT = 3;

    /** Receive the use case that lists the free spaces and the calculator. */
    public function __construct(
        private ListAvailableSlots $available,
        private SlotCalculator $calculator,
    ) {}

    /**
     * List up to 3 free spaces of the same collaborator, service and day as a requested start, the closest to it (RE-02).
     * The reservation of #56 offers them when the space asked for is taken.
     *
     * @return list<Slot>
     */
    public function execute(string $serviceId, string $collaboratorId, DateTimeImmutable $requestedStart, DateTimeImmutable $now): array
    {
        $slots = $this->available->execute($serviceId, CalendarDate::fromMoment($requestedStart)->value, $collaboratorId, $now);

        return $this->calculator->nearest($slots, $requestedStart, self::LIMIT);
    }
}
