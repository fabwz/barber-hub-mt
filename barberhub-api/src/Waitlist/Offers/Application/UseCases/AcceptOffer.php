<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\UseCases;

use DateTimeImmutable;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorNames;
use Src\Waitlist\Offers\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Application\Contracts\OfferedBookings;
use Src\Waitlist\Offers\Application\DTOs\OfferView;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\Exceptions\OfferAlreadyAnsweredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferExpiredException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferNotAnswerableException;
use Src\Waitlist\Offers\Domain\Exceptions\OfferNotFoundException;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\ReservationId;

final readonly class AcceptOffer
{
    /** Receive the repository of the offers, the ports to the line, the customer roles, the lock of the collaborator and the reservations, and the transaction port. */
    public function __construct(
        private OfferRepository $offers,
        private LineEntries $line,
        private CustomerMemberships $customers,
        private CollaboratorLock $collaborators,
        private CollaboratorNames $names,
        private OfferedBookings $bookings,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Accept the offer of a customer (RF-32): the space it holds is booked for them in the same transaction, by the reservations module, so the
     * rules of a booking all apply (RE-06, RE-07, PA-06, BO-04). The offer is looked for among those of the customer's own rows, found from the
     * session and never from the request, so an offer of another customer or barbershop, one that does not exist and a person without a role answer
     * the same (CL-04, MT-03). The collaborator is locked first, as an expiry does, so an answer and an expiry of the same offer run one after the
     * other, and the offer is read again under its own lock.
     *
     * With a voucher the reservation is confirmed at once without a deposit and a session is set aside (RE-07, RF-28, BO-04). Without one it
     * is confirmed at once if the barbershop charges nothing and otherwise it is temporary, and the customer pays it with the flow of the payments:
     * it may be paid until the offer would have expired (LE-04), and only its payment, confirmed by the gateway, accepts the offer and fulfils the row
     * (PA-06); if the time runs out, the offer is lost (LE-05). Either way the confirmation of the reservation is what accepts the offer, in the same
     * transaction (SettleAnsweredOffer). The offer points at its reservation before it exists, which is what stops it from holding the space against its
     * own booking (DI-05); if the booking is refused, for a voucher without sessions or a space that is no longer free, everything is undone and the
     * offer is still pending.
     *
     * It is idempotent: an accepted offer, and a pending one whose reservation still waits for its payment, answer as they are, without booking
     * again. An offer past its 15 minutes answers `oferta-vencida`, one turned down is refused, and one whose row the person left is refused too.
     *
     * @throws OfferNotFoundException
     * @throws OfferExpiredException
     * @throws OfferAlreadyAnsweredException
     * @throws OfferNotAnswerableException
     */
    public function execute(string $userId, string $offerId, bool $withVoucher, DateTimeImmutable $now): OfferView
    {
        return $this->transaction->run(function () use ($userId, $offerId, $withVoucher, $now): OfferView {
            $membership = $this->customers->find($userId) ?? throw OfferNotFoundException::create();
            $id = new OfferId($offerId);
            $seen = $this->offers->find($id) ?? throw OfferNotFoundException::create();
            $row = $this->line->find($seen->entryId->value);

            if ($row === null || $row->customerMembershipId !== $membership) {
                throw OfferNotFoundException::create();
            }

            $this->collaborators->lock($seen->collaboratorId->value);
            $offer = $this->offers->findForUpdate($id) ?? throw OfferNotFoundException::create();

            if ($offer->status === OfferStatus::Accepted) {
                return $this->view($offer, $row->serviceId);
            }

            $offer->assertOpenAt($now);

            if ($offer->reservationId !== null && $this->bookings->isAwaitingPayment($offer->reservationId->value, $now)) {
                return $this->view($offer, $row->serviceId);
            }

            if (! $row->offered) {
                throw OfferNotAnswerableException::create();
            }

            $reservationId = $this->bookings->nextReservationId();
            $offer->answerWith(new ReservationId($reservationId));
            $this->offers->save($offer);
            $this->bookings->book(
                $userId,
                $row->serviceId,
                $offer->collaboratorId->value,
                $offer->interval->start,
                $withVoucher,
                $reservationId,
                $offer->expiresAt,
                $now,
            );

            return $this->view($this->offers->find($id) ?? $offer, $row->serviceId);
        });
    }

    /** Carry the offer with the name of its collaborator, read once. */
    private function view(Offer $offer, string $serviceId): OfferView
    {
        return new OfferView($offer, $serviceId, $this->names->names()[$offer->collaboratorId->value] ?? null);
    }
}
