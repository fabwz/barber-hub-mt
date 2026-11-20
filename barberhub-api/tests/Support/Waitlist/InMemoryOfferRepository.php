<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

/** The offers of the test, in memory, with the same questions the real repository answers. */
final class InMemoryOfferRepository implements OfferRepository
{
    /** @var array<string, Offer> */
    private array $offers = [];

    private int $sequence = 0;

    /** Generate a predictable identity. */
    public function nextId(): OfferId
    {
        return new OfferId(sprintf('0192f0c4-6666-7000-8000-%012d', ++$this->sequence));
    }

    /** Keep the offer. */
    public function save(Offer $offer): void
    {
        $this->offers[$offer->id->value] = $offer;
    }

    /** The offer, if it exists. */
    public function find(OfferId $id): ?Offer
    {
        return $this->offers[$id->value] ?? null;
    }

    /** The offer, if it exists. */
    public function findForUpdate(OfferId $id): ?Offer
    {
        return $this->offers[$id->value] ?? null;
    }

    /** Whether an offer of the row, in any state, is for the same collaborator and shares a minute with the space. */
    public function hadOfferOverlapping(WaitlistEntryId $entry, CollaboratorId $collaborator, TimeInterval $space): bool
    {
        return array_any($this->offers, fn (Offer $offer): bool => $offer->entryId->equals($entry)
            && $offer->collaboratorId->equals($collaborator)
            && $offer->interval->overlaps($space));
    }

    /**
     * Whether a pending offer inside its time, of any of the rows, shares a minute with the space.
     *
     * @param  list<WaitlistEntryId>  $entries
     */
    public function hasPendingOverlapping(array $entries, TimeInterval $space, DateTimeImmutable $now): bool
    {
        return array_any($this->offers, fn (Offer $offer): bool => $offer->holdsSpaceAt($now)
            && array_any($entries, fn (WaitlistEntryId $entry): bool => $entry->equals($offer->entryId))
            && $offer->interval->overlaps($space));
    }

    /** Whether a pending offer inside its time holds some minute of the space of the collaborator, as the availability sees it (DI-05). */
    public function holds(CollaboratorId $collaborator, TimeInterval $space, DateTimeImmutable $now): bool
    {
        return array_any($this->offers, fn (Offer $offer): bool => $offer->holdsSpaceAt($now)
            && $offer->collaboratorId->equals($collaborator)
            && $offer->interval->overlaps($space));
    }

    /**
     * Every offer kept, in the order they were made.
     *
     * @return list<Offer>
     */
    public function all(): array
    {
        return array_values($this->offers);
    }
}
