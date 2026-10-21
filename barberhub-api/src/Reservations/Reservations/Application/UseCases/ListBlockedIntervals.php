<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use Src\Reservations\Reservations\Application\Contracts\BlockedIntervalReader;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class ListBlockedIntervals
{
    /** Receive the port that reads the intervals held by reservations. */
    public function __construct(private BlockedIntervalReader $reader) {}

    /**
     * List the intervals some collaborators have held in a window by temporary or confirmed reservations, cleaning included;
     * the availability reads it (DI-01, DI-05, RE-01).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function execute(array $collaboratorIds, TimeInterval $window): array
    {
        if ($collaboratorIds === []) {
            return [];
        }

        return $this->reader->between($collaboratorIds, $window);
    }
}
