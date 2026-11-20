<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Application\UseCases;

use DateTimeImmutable;
use Src\Availability\Slots\Application\Contracts\BlockedIntervals;
use Src\Shared\Domain\ValueObjects\TimeInterval;

final readonly class IsSpaceFree
{
    /** Receive the port that gives what reservations and offers hold. */
    public function __construct(private BlockedIntervals $blocked) {}

    /**
     * Whether no temporary or confirmed reservation and no pending offer holds any minute of the space for the collaborator (DI-05). It reads
     * again every time, so it is only as good as the lock of the collaborator held around it. A reservation or an offer past its time holds
     * nothing, and the spaces that merely touch the interval do not count.
     */
    public function execute(string $collaboratorId, TimeInterval $space, DateTimeImmutable $now): bool
    {
        return ($this->blocked->between([$collaboratorId], $space, $now)[$collaboratorId] ?? []) === [];
    }
}
