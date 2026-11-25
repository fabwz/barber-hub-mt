<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Events\OfferAnswered;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;

final readonly class TurnOfferDown
{
    /** Receive the repository of the offers, the port to the line, the events of the domain and the barbershop of the operation. */
    public function __construct(
        private OfferRepository $offers,
        private WaitingLine $line,
        private DomainEvents $events,
        private BarbershopContext $context,
    ) {}

    /**
     * Close an offer as turned down, which is what its person does by rejecting it (RF-32) and what cancelling the reservation that was made for it
     * amounts to (LE-05): the offer goes to `rejected`, the row goes back to waiting with the place it had and the pop-up of the person is told that the
     * offer is closed (RF-35). It is the part that both ways share; offering the space to the next person is left to each of them. The caller holds the
     * transaction and the lock of the offer, and has checked that it may be turned down: an offer past its time or already accepted throws.
     */
    public function execute(Offer $offer, string $customerMembershipId, DateTimeImmutable $now): void
    {
        $offer->reject($now);
        $this->offers->save($offer);
        $this->line->returnToLine($offer->entryId->value, $now);
        $this->events->publish(new OfferAnswered($this->context->current()->value, $offer->id->value, $customerMembershipId, OfferStatus::Rejected->value));
    }
}
