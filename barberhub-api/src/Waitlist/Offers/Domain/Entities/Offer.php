<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Entities;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Exceptions\OfferNotExpirableException;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

final class Offer
{
    /** Minutes an offer keeps its space for the person in exclusive (LE-04). */
    public const int LIFETIME_MINUTES = 15;

    /** Hold the full state of an offer. */
    private function __construct(
        public readonly OfferId $id,
        public readonly WaitlistEntryId $entryId,
        public readonly CollaboratorId $collaboratorId,
        public readonly TimeInterval $interval,
        public readonly DateTimeImmutable $expiresAt,
        public private(set) OfferStatus $status,
        public private(set) ?DateTimeImmutable $respondedAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    /**
     * Offer a space to a row of the line. It starts pending and keeps the space for the person for 15 minutes, counted from the
     * instant it is made (LE-04); the instant arrives as a parameter, as the domain never asks for the time.
     */
    public static function create(OfferId $id, WaitlistEntryId $entryId, CollaboratorId $collaboratorId, TimeInterval $interval, DateTimeImmutable $at): self
    {
        return new self($id, $entryId, $collaboratorId, $interval, $at->modify('+'.self::LIFETIME_MINUTES.' minutes'), OfferStatus::Pending, null, $at);
    }

    /** Rebuild an offer from what is stored, without any check. */
    public static function reconstitute(
        OfferId $id,
        WaitlistEntryId $entryId,
        CollaboratorId $collaboratorId,
        TimeInterval $interval,
        DateTimeImmutable $expiresAt,
        OfferStatus $status,
        ?DateTimeImmutable $respondedAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $entryId, $collaboratorId, $interval, $expiresAt, $status, $respondedAt, $createdAt);
    }

    /** Whether the offer still waits for an answer; it may be overdue and not marked yet. */
    public function isPending(): bool
    {
        return $this->status === OfferStatus::Pending;
    }

    /** Whether the 15 minutes passed without an answer at the instant: it is pending and its time is up, even if nobody marked it yet (LE-05). */
    public function isOverdueAt(DateTimeImmutable $at): bool
    {
        return $this->isPending() && $at >= $this->expiresAt;
    }

    /** Whether the offer keeps its space away from other people at the instant (DI-05): pending and inside its 15 minutes. */
    public function holdsSpaceAt(DateTimeImmutable $at): bool
    {
        return $this->isPending() && $at < $this->expiresAt;
    }

    /**
     * Mark the offer as expired once its 15 minutes passed without an answer (LE-04, LE-05). It is idempotent, so a repeated or retried
     * task changes nothing; an answered offer, or one still inside its time, cannot expire.
     *
     * @throws OfferNotExpirableException
     */
    public function expire(DateTimeImmutable $at): void
    {
        if ($this->status === OfferStatus::Expired) {
            return;
        }

        if (! $this->isOverdueAt($at)) {
            throw OfferNotExpirableException::create();
        }

        $this->status = OfferStatus::Expired;
    }
}
