<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Events\OfferAnswered;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAlreadyAnsweredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferExpiredException;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\ReservationId;

final readonly class SettleAnsweredOffer
{
    /** Receive the repository of the offers, the port to the line, the transaction port, the events of the domain and the barbershop of the operation. */
    public function __construct(
        private OfferRepository $offers,
        private LineEntries $line,
        private TransactionRunner $transaction,
        private DomainEvents $events,
        private BarbershopContext $context,
    ) {}

    /**
     * Run when a reservation is confirmed (PA-06, RE-07): if an offer waits for it, the offer is accepted and its row leaves the line as
     * fulfilled (RF-32). It is what makes the payment, confirmed by the gateway, and nothing the browser reports, the answer to an offer. A
     * reservation no offer points at, an offer already accepted and one that is no longer pending change nothing, so a retry is harmless. An offer
     * whose time ran out is left to its own expiry: the reservation could not have been confirmed after the same instant, which is its limit (LE-04).
     * A row that the person left while paying stays out of the line, and the offer is accepted all the same, because the reservation is theirs. It tells the pop-up of the person that the offer was accepted (RF-35). It
     * joins the transaction of whatever confirmed the reservation, so the two are atomic.
     */
    public function execute(string $reservationId, DateTimeImmutable $now): void
    {
        $this->transaction->run(function () use ($reservationId, $now): void {
            $offer = $this->offers->findByReservationForUpdate(new ReservationId($reservationId));

            if ($offer === null || ! $offer->isPending()) {
                return;
            }

            try {
                $offer->accept($now);
            } catch (OfferExpiredException|OfferAlreadyAnsweredException) {
                return;
            }

            $this->offers->save($offer);
            $row = $this->line->find($offer->entryId->value);
            $this->line->fulfil($offer->entryId->value, $now);

            if ($row !== null) {
                $this->events->publish(new OfferAnswered($this->context->current()->value, $offer->id->value, $row->customerMembershipId, OfferStatus::Accepted->value));
            }
        });
    }
}
