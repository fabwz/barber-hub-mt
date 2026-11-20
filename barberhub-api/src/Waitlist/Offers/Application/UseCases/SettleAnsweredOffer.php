<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAlreadyAnsweredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferExpiredException;
use Src\Waitlist\Offers\Domain\ValueObjects\ReservationId;

final readonly class SettleAnsweredOffer
{
    /** Receive the repository of the offers, the port to the line and the transaction port. */
    public function __construct(
        private OfferRepository $offers,
        private LineEntries $line,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Run when a reservation is confirmed (PA-06, RE-07): if an offer waits for it, the offer is accepted and its row leaves the line as
     * fulfilled (RF-32). It is what makes the payment, confirmed by the gateway, and nothing the browser reports, the answer to an offer. A
     * reservation no offer points at, an offer already accepted and one that is no longer pending change nothing, so a retry is harmless. An offer
     * whose time ran out is left to its own expiry: the reservation could not have been confirmed after the same instant, which is its limit (LE-04).
     * A row that the person left while paying stays out of the line, and the offer is accepted all the same, because the reservation is theirs. It
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
            $this->line->fulfil($offer->entryId->value, $now);
        });
    }
}
