<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use DateTimeImmutable;
use Src\Reservations\Reservations\Domain\ValueObjects\SlotAlternative;

interface ProposalAvailability
{
    /**
     * The free spaces of a day for a service, of one collaborator or of all who offer it, in order of start, by the same
     * calculation the availability shows (DI-01 to DI-03, DI-05).
     *
     * @return list<SlotAlternative>
     */
    public function onDay(string $serviceId, ?string $collaboratorId, string $date, DateTimeImmutable $now): array;

    /**
     * Up to 3 free spaces of the same day and service, of one collaborator or of all who offer it, the closest to the requested
     * start: the same alternatives the booking offers when a space is taken (RE-02).
     *
     * @return list<SlotAlternative>
     */
    public function closest(string $serviceId, ?string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now): array;
}
