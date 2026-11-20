<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Application\Contracts\OfferedBookings;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Application\DTOs\OfferView;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAlreadyAnsweredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAwaitingPaymentException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferExpiredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferNotFoundException;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;

final readonly class RejectOffer
{
    /** Receive the repository of the offers, the ports to the line, the customer roles, the lock of the collaborator and the reservations, the offering of a freed space and the transaction port. */
    public function __construct(
        private OfferRepository $offers,
        private LineEntries $entries,
        private WaitingLine $line,
        private CustomerMemberships $customers,
        private CollaboratorLock $collaborators,
        private OfferedBookings $bookings,
        private OfferReleasedSpace $offerSpace,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Turn down the offer of a customer (RF-32). The offer is looked for among those of the customer's own rows, as accepting does (CL-04, MT-03),
     * after the collaborator is locked, so an answer and an expiry of the same offer run one after the other. The offer goes to `rejected`, the row
     * goes back to waiting with the place it had, as when an offer expires, and in the same operation the same space is offered to the next compatible
     * person, without waiting for the 15 minutes (LE-05). The person who turned it down is not offered that space again, but keeps their place for
     * other spaces; if nobody is left, or fewer than 60 minutes remain, the space is public again (LE-03).
     *
     * It is idempotent: turning down a rejected offer changes nothing and offers nothing again. An offer past its 15 minutes answers
     * `oferta-vencida`, an accepted one is refused, and so is one whose reservation still waits for its payment: the person cancels that reservation first.
     *
     * @throws OfferNotFoundException
     * @throws OfferExpiredException
     * @throws OfferAlreadyAnsweredException
     * @throws OfferAwaitingPaymentException
     */
    public function execute(string $userId, string $offerId, DateTimeImmutable $now): OfferView
    {
        return $this->transaction->run(function () use ($userId, $offerId, $now): OfferView {
            $membership = $this->customers->find($userId) ?? throw OfferNotFoundException::create();
            $id = new OfferId($offerId);
            $seen = $this->offers->find($id) ?? throw OfferNotFoundException::create();
            $row = $this->entries->find($seen->entryId->value);

            if ($row === null || $row->customerMembershipId !== $membership) {
                throw OfferNotFoundException::create();
            }

            $this->collaborators->lock($seen->collaboratorId->value);
            $offer = $this->offers->findForUpdate($id) ?? throw OfferNotFoundException::create();

            if ($offer->status === OfferStatus::Rejected) {
                return new OfferView($offer, $row->serviceId);
            }

            $offer->assertOpenAt($now);

            if ($offer->reservationId !== null && $this->bookings->isAwaitingPayment($offer->reservationId->value, $now)) {
                throw OfferAwaitingPaymentException::create();
            }

            $offer->reject($now);
            $this->offers->save($offer);
            $this->line->returnToLine($offer->entryId->value, $now);
            $this->offerSpace->execute($offer->collaboratorId->value, $offer->interval->start, $offer->interval->end, $now);

            return new OfferView($offer, $row->serviceId);
        });
    }
}
