<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use Src\Shared\Domain\ValueObjects\TimeInterval;

/** The time a collaborator stopped holding, whole and with its cleaning, which the line may offer (RF-31). */
final readonly class ReleasedSpace
{
    /** Minutes before the space starts after which it is not offered anymore (LE-03). */
    public const int MIN_NOTICE_MINUTES = 60;

    private const string ZONE = 'America/Costa_Rica';

    /** Hold the collaborator and the interval they held. */
    public function __construct(
        public CollaboratorId $collaboratorId,
        public TimeInterval $interval,
    ) {}

    /**
     * Whether there is enough notice to offer the space at the instant: it is offered while at least 60 minutes remain before it starts, and
     * not when fewer remain (LE-03). Exactly 60 is enough, as in the booking window (DI-03).
     */
    public function isOfferableAt(DateTimeImmutable $now): bool
    {
        return $this->interval->start >= $now->modify('+'.self::MIN_NOTICE_MINUTES.' minutes');
    }

    /** The day of the space in Costa Rica, the way a customer names the day they wait for. */
    public function date(): string
    {
        return $this->interval->start->setTimezone(new DateTimeZone(self::ZONE))->format('Y-m-d');
    }

    /** Whether a service that needs that many minutes, cleaning included, fits in the whole space: a hole of 30 minutes is not offered to someone who waits for 60 (LE-03). */
    public function fits(int $minutes): bool
    {
        return $this->interval->duration()->minutes >= $minutes;
    }
}
