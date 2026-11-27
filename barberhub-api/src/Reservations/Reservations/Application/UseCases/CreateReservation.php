<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use LogicException;
use Src\Reservations\Reservations\Application\Contracts\AnyAvailableCollaborators;
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\Contracts\CancellationDeadlines;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Application\Contracts\PaymentRequirement;
use Src\Reservations\Reservations\Application\Contracts\ReservationExpiries;
use Src\Reservations\Reservations\Application\Contracts\ReservationVouchers;
use Src\Reservations\Reservations\Application\DTOs\BookableService;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationCreated;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\ServiceNotBookableException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\Exceptions\VoucherNotRequiredException;
use Src\Reservations\Reservations\Domain\ValueObjects\AssistedClientId;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CreatorMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\PurchasedVoucherId;
use Src\Reservations\Reservations\Domain\ValueObjects\ReservationId;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\DomainEvents;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CreateReservation
{
    /** Receive the reservation repository, the ports to the neighbouring modules (the free spaces and the assignment of "any available" among them), the expiry of overdue reservations, the transaction port, the vouchers port, the port that schedules the expiry of a temporary reservation, the events of the domain and the barbershop of the operation. */
    public function __construct(
        private ReservationRepository $reservations,
        private BookableServices $services,
        private CancellationDeadlines $deadlines,
        private CollaboratorLock $collaborators,
        private FreeSlots $slots,
        private AnyAvailableCollaborators $anyAvailable,
        private CustomerMemberships $customers,
        private PaymentRequirement $payment,
        private ExpireOverdueReservations $expireOverdue,
        private TransactionRunner $transaction,
        private ReservationVouchers $vouchers,
        private ReservationExpiries $expiries,
        private DomainEvents $events,
        private BarbershopContext $context,
    ) {}

    /**
     * Book a space for a customer (RF-11). In one transaction it locks the collaborator, checks again that the space is
     * free (never trusting an earlier check), creates the customer role if the person has none yet (UP-09), checks the
     * customer has no other confirmed reservation at that time (RE-06) and stores the reservation with the price, duration
     * and the cancellation deadline the barbershop has now (SV-03, CA-01), so a later change of the deadline leaves it as it
     * was. Right after it locks the collaborator and before it checks the space, it expires the temporary reservations of
     * that collaborator whose payment window closed, so the exclusion of the database does not collide with them (RE-05).
     * If the barbershop charges, the reservation stays temporary and expires 10 minutes after it was made unless its
     * payment confirms it (RE-05, PA-06), and a task is left for that moment so it expires on time and frees its space for the
     * waitlist even if nobody asks for the collaborator; if not, it is confirmed right away (RE-07), so its history has two entries:
     * creation and confirmation. If anything fails the transaction is
     * rolled back and no membership is left. When the customer pays with a voucher (RF-28) and the barbershop charges, the
     * reservation is confirmed right away without a deposit (RE-07) and, once it is stored, a session of a valid voucher that
     * covers the service is set aside in the same transaction, the one that expires first (BO-04, BO-08); if none can be, the
     * transaction is rolled back and nothing is left, not even the membership. In a barbershop that does not charge there is nothing
     * to replace, so asking for a voucher is rejected before anything is written and no session is used. When the space is taken, by the check or by the exclusion of the database
     * (the second barrier, ADR 0009), the error carries up to 3 alternatives, read after the rollback (RE-02). A reservation that is confirmed
     * here announces it (ReservationConfirmed), so the lines of the waitlist that the person waits in for that service and day are left (LE-06).
     * The waitlist, when it books the space of an offer, gives the identifier the reservation will have and the limit of its payment, the end of
     * the 15 minutes of the offer (LE-04); nobody else does.
     *
     * @throws SlotUnavailableException
     * @throws OverlappingReservationException
     * @throws VoucherNotRequiredException
     */
    public function execute(CreateReservationCommand $command, DateTimeImmutable $now): Reservation
    {
        $service = $this->services->find($command->serviceId) ?? throw ServiceNotBookableException::create();
        $author = Author::person(new UserId($command->userId));

        if ($command->withVoucher && ! $this->payment->isRequired()) {
            throw VoucherNotRequiredException::create();
        }

        try {
            return $this->transaction->run(function () use ($command, $service, $author, $now): Reservation {
                $collaboratorId = $this->claimCollaborator($command, $now);
                $reservation = $command->assistedClientId === null
                    ? $this->newOnlineReservation($command, $service, $collaboratorId, $author, $now)
                    : $this->newAssistedReservation($command, $command->assistedClientId, $service, $collaboratorId, $author, $now);

                $customer = $reservation->customerMembershipId;

                if ($customer !== null && $this->reservations->hasConfirmedOverlapping($customer, $reservation->interval)) {
                    throw OverlappingReservationException::create();
                }

                if ($command->withVoucher) {
                    $reservation->confirm($now, $author);
                    $this->reservations->save($reservation);
                    $reservation->payWithVoucher(new PurchasedVoucherId($this->vouchers->hold(
                        ($customer ?? throw new LogicException('A voucher pays an online reservation.'))->value,
                        $command->serviceId,
                        $reservation->id->value,
                        $command->startsAt,
                        $now,
                    )));
                } elseif ($reservation->isAssisted() || ! $this->payment->isRequired()) {
                    $reservation->confirm($now, $author);
                }

                $this->reservations->save($reservation);

                if ($reservation->expiresAt !== null) {
                    $this->expiries->schedule($reservation->id->value, $reservation->expiresAt);
                    $this->events->publish(new ReservationCreated(
                        $this->context->current()->value,
                        $reservation->id->value,
                        $reservation->customerMembershipId?->value,
                        $reservation->serviceId->value,
                        $reservation->collaboratorId->value,
                        $reservation->interval->start,
                        $reservation->interval->end,
                    ));
                } else {
                    $this->events->publish(new ReservationConfirmed(
                        $this->context->current()->value,
                        $reservation->id->value,
                        $reservation->customerMembershipId?->value,
                        $reservation->serviceId->value,
                        $reservation->collaboratorId->value,
                        $reservation->interval->start,
                        $reservation->interval->end,
                        $reservation->assistedClientId?->value,
                    ));
                }

                return $reservation;
            });
        } catch (SlotUnavailableException $exception) {
            throw $exception->withAlternatives(
                $command->collaboratorId === null
                    ? $this->anyAvailable->alternatives($command->serviceId, $command->startsAt, $now)
                    : $this->slots->alternatives($command->serviceId, $command->collaboratorId, $command->startsAt, $now),
            );
        }
    }

    /** Start the reservation of the person of the session, who becomes a customer of the barbershop if they were not (UP-09). */
    private function newOnlineReservation(CreateReservationCommand $command, BookableService $service, string $collaboratorId, Author $author, DateTimeImmutable $now): Reservation
    {
        return Reservation::create(
            $command->reservationId === null ? $this->reservations->nextId() : new ReservationId($command->reservationId),
            new CollaboratorId($collaboratorId),
            new ServiceId($command->serviceId),
            new CustomerMembershipId($this->customers->ensure($command->userId)),
            $command->startsAt,
            $service->price,
            $service->duration,
            $this->deadlines->current(),
            $author,
            $now,
            $command->payUntil,
        );
    }

    /** Start the reservation the owner registers for an assisted client, who has no role and pays nothing online (RF-12, RE-07). */
    private function newAssistedReservation(CreateReservationCommand $command, string $assistedClientId, BookableService $service, string $collaboratorId, Author $author, DateTimeImmutable $now): Reservation
    {
        return Reservation::createAssisted(
            $this->reservations->nextId(),
            new CollaboratorId($collaboratorId),
            new ServiceId($command->serviceId),
            new AssistedClientId($assistedClientId),
            new CreatorMembershipId($command->createdByMembershipId ?? throw new LogicException('An assisted reservation records who registered it.')),
            $command->startsAt,
            $service->price,
            $service->duration,
            $this->deadlines->current(),
            $author,
            $now,
        );
    }

    /**
     * Decide who attends and leave them locked, inside the transaction. With a collaborator named, it locks them, expires their overdue
     * temporary reservations and checks the space is still free. With "any available" it locks every candidate in the order of their
     * identifiers, so two requests never wait for each other's locks in opposite order, expires their overdue reservations, and only then
     * asks for the assignment of DI-04, which reads and counts again with nobody able to take a space meanwhile. If nobody is free the
     * space is taken, as for a named collaborator.
     *
     * @throws SlotUnavailableException
     */
    private function claimCollaborator(CreateReservationCommand $command, DateTimeImmutable $now): string
    {
        if ($command->collaboratorId !== null) {
            $this->lockAndExpire($command->collaboratorId, $now);

            return $this->slots->isFree($command->serviceId, $command->collaboratorId, $command->startsAt, $now)
                ? $command->collaboratorId
                : throw SlotUnavailableException::create();
        }

        $candidates = $this->anyAvailable->candidates($command->serviceId, $command->startsAt);
        sort($candidates);

        foreach ($candidates as $candidate) {
            $this->lockAndExpire($candidate, $now);
        }

        return $this->anyAvailable->assign($command->serviceId, $command->startsAt, $now) ?? throw SlotUnavailableException::create();
    }

    /** Lock a collaborator and expire the temporary reservations of theirs whose payment window closed (RE-01, RE-05). */
    private function lockAndExpire(string $collaboratorId, DateTimeImmutable $now): void
    {
        $this->collaborators->lock($collaboratorId);
        $this->expireOverdue->execute($collaboratorId, $now);
    }
}
