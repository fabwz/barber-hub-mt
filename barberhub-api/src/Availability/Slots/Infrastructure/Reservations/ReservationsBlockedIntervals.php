<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure\Reservations;

use Src\Availability\Slots\Application\Contracts\BlockedIntervals;
use Src\Reservations\Reservations\Application\UseCases\ListBlockedIntervals;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class ReservationsBlockedIntervals implements BlockedIntervals
{
    /** Receive the Reservations use case that lists the intervals held by reservations. */
    public function __construct(private ListBlockedIntervals $listBlocked) {}

    /**
     * List the intervals held by the reservations of some collaborators in a window through the Reservations module.
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function between(array $collaboratorIds, TimeInterval $window, ?string $ignoringReservationId = null): array
    {
        return $this->listBlocked->execute($collaboratorIds, $window, $ignoringReservationId);
    }
}
