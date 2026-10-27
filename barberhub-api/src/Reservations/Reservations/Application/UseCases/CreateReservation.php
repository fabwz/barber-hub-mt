<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\Contracts\CancellationDeadlines;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\ServiceNotBookableException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Reservations\Reservations\Domain\ValueObjects\Author;
use Src\Reservations\Reservations\Domain\ValueObjects\CollaboratorId;
use Src\Reservations\Reservations\Domain\ValueObjects\CustomerMembershipId;
use Src\Reservations\Reservations\Domain\ValueObjects\ServiceId;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CreateReservation
{
    /** Receive the reservation repository, the ports to the neighbouring modules and the transaction port. */
    public function __construct(
        private ReservationRepository $reservations,
        private BookableServices $services,
        private CancellationDeadlines $deadlines,
        private CollaboratorLock $collaborators,
        private FreeSlots $slots,
        private CustomerMemberships $customers,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Book a space for a customer (RF-11). In one transaction it locks the collaborator, checks again that the space is
     * free (never trusting an earlier check), creates the customer role if the person has none yet (UP-09), checks the
     * customer has no other confirmed reservation at that time (RE-06) and stores the reservation with the price, duration
     * and the cancellation deadline the barbershop has now (SV-03, CA-01), so a later change of the deadline leaves it as it
     * was. Until the payments arrive (#62 to #64) every reservation is confirmed right away (RE-07), so its history has two
     * entries: creation and confirmation. If anything fails the transaction is
     * rolled back and no membership is left. When the space is taken, by the check or by the exclusion of the database
     * (the second barrier, ADR 0009), the error carries up to 3 alternatives, read after the rollback (RE-02).
     *
     * @throws SlotUnavailableException
     * @throws OverlappingReservationException
     */
    public function execute(CreateReservationCommand $command, DateTimeImmutable $now): Reservation
    {
        $service = $this->services->find($command->serviceId) ?? throw ServiceNotBookableException::create();
        $author = Author::person(new UserId($command->userId));

        try {
            return $this->transaction->run(function () use ($command, $service, $author, $now): Reservation {
                $this->collaborators->lock($command->collaboratorId);

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

                $reservation->confirm($now, $author);
                $this->reservations->save($reservation);

                return $reservation;
            });
        } catch (SlotUnavailableException $exception) {
            throw $exception->withAlternatives(
                $this->slots->alternatives($command->serviceId, $command->collaboratorId, $command->startsAt, $now),
            );
        }
    }
}
