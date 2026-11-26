<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\ValueObjects\ReservationId;

final readonly class ReleaseOfferOfCancelledReservation
{
    /** Receive the repository of the offers, the port to the rows of the line, the use case that turns an offer down and the transaction port. */
    public function __construct(
        private OfferRepository $offers,
        private LineEntries $entries,
        private TurnOfferDown $turnDown,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Run when a reservation is cancelled: if an offer is waiting for it, cancelling the temporary reservation that the person made to pay for the offer
     * is the same as not confirming it, so the offer is closed as if they had turned it down (LE-05). The offer goes to `rejected`, its row goes back to
     * waiting with its place and its pop-up is told; the space that the cancellation freed is offered to the next person by the release that the same
     * cancellation announces, and the person who cancelled is not offered it again, because their own offer for that space is already in the record.
     *
     * Everything else changes nothing and fails with nothing, so a retry is harmless: a reservation that no offer points at, an offer that is no longer
     * pending (accepted by its payment, turned down, expired) and an offer whose 15 minutes are up, which is left to its own expiry (LE-04, LE-05). It
     * joins the transaction of the cancellation, with its barbershop already set, and the row of the offer is locked, so it and an expiry or an
     * answer to the same offer run one after the other, whoever comes first.
     */
    public function execute(string $reservationId, DateTimeImmutable $now): void
    {
        $this->transaction->run(function () use ($reservationId, $now): void {
            $offer = $this->offers->findByReservationForUpdate(new ReservationId($reservationId));

            if ($offer === null || ! $offer->isPending() || $offer->isOverdueAt($now)) {
                return;
            }

            $row = $this->entries->find($offer->entryId->value);

            if ($row === null) {
                return;
            }

            $this->turnDown->execute($offer, $row->customerMembershipId, $now);
        });
    }
}
