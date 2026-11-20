<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\Contracts\CancellationDeadlines;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Application\Contracts\PaymentRequirement;
use Src\Reservations\Reservations\Application\Contracts\ReservationExpiries;
use Src\Reservations\Reservations\Application\Contracts\ReservationVouchers;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\ServiceNotBookableException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\Exceptions\VoucherNotRequiredException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\PurchasedVoucherId;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CreateReservation
{
    /** Receive the reservation repository, the ports to the neighbouring modules, the expiry of overdue reservations, the transaction port, the vouchers port and the port that schedules the expiry of a temporary reservation. */
    public function __construct(
        private ReservationRepository $reservations,
        private BookableServices $services,
        private CancellationDeadlines $deadlines,
        private CollaboratorLock $collaborators,
        private FreeSlots $slots,
        private CustomerMemberships $customers,
        private PaymentRequirement $payment,
        private ExpireOverdueReservations $expireOverdue,
        private TransactionRunner $transaction,
        private ReservationVouchers $vouchers,
        private ReservationExpiries $expiries,
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
     * (the second barrier, ADR 0009), the error carries up to 3 alternatives, read after the rollback (RE-02).
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
                $this->collaborators->lock($command->collaboratorId);
                $this->expireOverdue->execute($command->collaboratorId, $now);

                if (! $this->slots->isFree($command->serviceId, $command->collaboratorId, $command->startsAt, $now)) {
                    throw SlotUnavailableException::create();
                }

                $customer = new CustomerMembershipId($this->customers->ensure($command->userId));
                $reservation = Reservation::create(
                    $this->reservations->nextId(),
                    new CollaboratorId($command->collaboratorId),
                    new ServiceId($command->serviceId),
                    $customer,
                    $command->startsAt,
                    $service->price,
                    $service->duration,
                    $this->deadlines->current(),
                    $author,
                    $now,
                );

                if ($this->reservations->hasConfirmedOverlapping($customer, $reservation->interval)) {
                    throw OverlappingReservationException::create();
                }

                if ($command->withVoucher) {
                    $reservation->confirm($now, $author);
                    $this->reservations->save($reservation);
                    $reservation->payWithVoucher(new PurchasedVoucherId($this->vouchers->hold(
                        $customer->value,
                        $command->serviceId,
                        $reservation->id->value,
                        $command->startsAt,
                        $now,
                    )));
                } elseif (! $this->payment->isRequired()) {
                    $reservation->confirm($now, $author);
                }

                $this->reservations->save($reservation);

                if ($reservation->expiresAt !== null) {
                    $this->expiries->schedule($reservation->id->value, $reservation->expiresAt);
                }

                return $reservation;
            });
        } catch (SlotUnavailableException $exception) {
            throw $exception->withAlternatives(
                $this->slots->alternatives($command->serviceId, $command->collaboratorId, $command->startsAt, $now),
            );
        }
    }
}
