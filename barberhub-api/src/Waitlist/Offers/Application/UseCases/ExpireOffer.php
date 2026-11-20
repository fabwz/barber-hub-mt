<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Events\OfferExpired;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;

final readonly class ExpireOffer
{
    /** Receive the repository of the offers, the line, the lock of the collaborator, the offering of a freed space, the transaction port, the events of the domain and the barbershop of the operation. */
    public function __construct(
        private OfferRepository $offers,
        private WaitingLine $line,
        private CollaboratorLock $collaborators,
        private OfferReleasedSpace $offerSpace,
        private TransactionRunner $transaction,
        private DomainEvents $events,
        private BarbershopContext $context,
    ) {}

    /**
     * Run when the 15 minutes of an offer run out (LE-04): if the offer is still pending and overdue, it locks its collaborator, as every
     * operation on their time does (RE-01), expires the offer, puts its row back in the line with the place it had and offers the same
     * space to the next compatible person (LE-05). The person who just let it expire is not offered it again. If nobody is left, or fewer than
     * 60 minutes remain (LE-03), or the waitlist was switched off (RF-09), the space is public again. It decides nothing from the instant the task
     * was made for: an offer that was answered, one that already expired, one that does not exist and one still inside its time are left
     * alone, so a retried or early task changes nothing. The line moves on in the same transaction as the expiry, so a failure leaves the offer
     * pending for the retry of the queue (RNF-09).
     *
     * @return bool Whether the offer was expired.
     */
    public function execute(string $offerId, DateTimeImmutable $now): bool
    {
        return $this->transaction->run(function () use ($offerId, $now): bool {
            $id = new OfferId($offerId);
            $seen = $this->offers->find($id);

            if ($seen === null || ! $seen->isOverdueAt($now)) {
                return false;
            }

            $this->collaborators->lock($seen->collaboratorId->value);
            $offer = $this->offers->findForUpdate($id);

            if ($offer === null || ! $offer->isOverdueAt($now)) {
                return false;
            }

            $offer->expire($now);
            $this->offers->save($offer);
            $this->line->returnToLine($offer->entryId->value, $now);
            $this->events->publish(new OfferExpired($this->context->current()->value, $offer->id->value, $offer->entryId->value));
            $this->offerSpace->execute($offer->collaboratorId->value, $offer->interval->start, $offer->interval->end, $now);

            return true;
        });
    }
}
