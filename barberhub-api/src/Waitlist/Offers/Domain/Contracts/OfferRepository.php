<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Domain\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;

interface OfferRepository
{
    /** Generate the identity for a new offer. */
    public function nextId(): OfferId;

    /** Persist a new offer, or the status and the answer instant of an existing one, among those of the current barbershop. */
    public function save(Offer $offer): void;

    /** Find an offer among those of the current barbershop, without locking it: the task that expires it reads it first to know whose collaborator to lock. */
    public function find(OfferId $id): ?Offer;

    /** Find an offer among those of the current barbershop and lock its row until the transaction ends, so a task and an answer run one after the other. */
    public function findForUpdate(OfferId $id): ?Offer;

    /**
     * Whether the row of the line already had an offer, in any state, for a space of the collaborator that shares some minute with the
     * interval. A person who let an offer expire or turned it down is not offered the same space again (LE-05).
     */
    public function hadOfferOverlapping(WaitlistEntryId $entry, CollaboratorId $collaborator, TimeInterval $space): bool;

    /**
     * Whether any of the rows of the line has a pending offer, inside its 15 minutes at the instant, for a space that shares some minute
     * with the interval: a person never receives two overlapping offers (LE-06). The rows are the ones of one customer.
     *
     * @param  list<WaitlistEntryId>  $entries
     */
    public function hasPendingOverlapping(array $entries, TimeInterval $space, DateTimeImmutable $now): bool;
}
