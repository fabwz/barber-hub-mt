<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\UseCases;

use DateTimeImmutable;
use LogicException;
use Src\Reservations\Reservations\Application\Contracts\AssistedCustomers;
use Src\Reservations\Reservations\Application\Contracts\StaffMemberships;
use Src\Reservations\Reservations\Application\DTOs\CreateReservationCommand;
use Src\Reservations\Reservations\Application\DTOs\RegisterAssistedReservationCommand;
use Src\Reservations\Reservations\Domain\Entities\Reservation;
use Src\Reservations\Reservations\Domain\Exceptions\AssistedCustomerNotFoundException;
use Src\Reservations\Reservations\Domain\Exceptions\OverlappingReservationException;
use Src\Reservations\Reservations\Domain\Exceptions\ServiceNotBookableException;
use Src\Reservations\Reservations\Domain\Exceptions\SlotUnavailableException;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class RegisterAssistedReservation
{
    /** Receive the use case that books a space, the port to the assisted clients, the port that tells the role of the owner and the transaction port. */
    public function __construct(
        private CreateReservation $createReservation,
        private AssistedCustomers $customers,
        private StaffMemberships $staff,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Register a reservation for a person who has no account (RF-12). In one transaction it finds the assisted client, or registers them with the
     * name, the phone and the optional email (CL-06), and books the space exactly as an online reservation does: the collaborator is locked, the space
     * is checked again and the exclusion of the database is the second barrier (RE-01), so an assisted reservation and an online one that compete
     * for the same space leave exactly one. Nobody pays online, so it is confirmed in the same operation (RE-07), with its history of
     * creation and confirmation, and the owner who registered it stays on record. If the space is taken the error carries up to 3 alternatives
     * and, since everything is rolled back, no assisted client is left behind. A client of another barbershop reads like one that does not exist.
     *
     * @throws AssistedCustomerNotFoundException
     * @throws ServiceNotBookableException
     * @throws SlotUnavailableException
     * @throws OverlappingReservationException
     */
    public function execute(RegisterAssistedReservationCommand $command, DateTimeImmutable $now): Reservation
    {
        return $this->transaction->run(function () use ($command, $now): Reservation {
            $owner = $this->staff->of($command->userId) ?? throw new LogicException('Only a person with a role in the barbershop registers an assisted reservation.');
            $clientId = $this->clientOf($command);

            return $this->createReservation->execute(new CreateReservationCommand(
                $command->userId,
                $command->serviceId,
                $command->collaboratorId,
                $command->startsAt,
                assistedClientId: $clientId,
                createdByMembershipId: $owner,
            ), $now);
        });
    }

    /** The assisted client the reservation is for: the one named, which must exist in this barbershop, or a new one registered now. */
    private function clientOf(RegisterAssistedReservationCommand $command): string
    {
        if ($command->assistedClientId !== null) {
            return $this->customers->exists($command->assistedClientId) ? $command->assistedClientId : throw AssistedCustomerNotFoundException::create();
        }

        return $this->customers->register((string) $command->name, (string) $command->phone, $command->email);
    }
}
