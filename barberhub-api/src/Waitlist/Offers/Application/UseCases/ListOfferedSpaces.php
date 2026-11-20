<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Application\Contracts\OfferedSpaceReader;

final readonly class ListOfferedSpaces
{
    /** Receive the port that reads the spaces held by offers. */
    public function __construct(private OfferedSpaceReader $reader) {}

    /**
     * List the spaces some collaborators have held in a window by pending offers, whole, which the availability subtracts so they do not appear
     * free to other people (DI-05). An offer past its 15 minutes holds nothing (LE-05).
     *
     * @param  list<string>  $collaboratorIds
     * @return array<string, list<TimeInterval>>
     */
    public function execute(array $collaboratorIds, TimeInterval $window, DateTimeImmutable $now): array
    {
        if ($collaboratorIds === []) {
            return [];
        }

        return $this->reader->between($collaboratorIds, $window, $now);
    }
}
