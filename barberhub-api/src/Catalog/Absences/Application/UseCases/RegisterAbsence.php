<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Absences\Application\Contracts\AbsenceCollaborators;
use Src\Catalog\Absences\Application\Contracts\ReservationConflicts;
use Src\Catalog\Absences\Application\DTOs\RegisterAbsenceCommand;
use Src\Catalog\Absences\Domain\Contracts\AbsenceRepository;
use Src\Catalog\Absences\Domain\Entities\Absence;
use Src\Catalog\Absences\Domain\Exceptions\AbsenceCollaboratorInactiveException;
use Src\Catalog\Absences\Domain\Exceptions\AbsenceWithReservationsException;
use Src\Catalog\Absences\Domain\Exceptions\InvalidAbsenceException;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceRange;
use Src\Catalog\Absences\Domain\ValueObjects\AbsenceReason;
use Src\Catalog\Absences\Domain\ValueObjects\CollaboratorId;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class RegisterAbsence
{
    /** How many of the reservations that stop an absence the error lists; it also tells how many there are in all. */
    public const int LISTED_RESERVATIONS = 50;

    /** Receive the repository, the ports toward Collaborators and Reservations and the transaction. */
    public function __construct(
        private AbsenceRepository $absences,
        private AbsenceCollaborators $collaborators,
        private ReservationConflicts $conflicts,
        private TransactionRunner $transaction,
    ) {}

    /**
     * Block the agenda of an active collaborator for a range of dates, with or without hours (RF-07, CO-06). The shape of the range
     * is checked first, before anything is locked. Then, in one transaction, the row of the collaborator is locked (the lock of the
     * reservations, RE-01), the reservations that hold time inside the range are read and, if there is any, nothing is saved: the
     * error lists the earliest ones for the owner to reschedule or cancel. A reservation that arrives at the same time either
     * shows up in that read or waits for the lock and then finds the absence (DI-01), so none is left inside it.
     *
     * @throws InvalidAbsenceException
     * @throws AbsenceCollaboratorInactiveException
     * @throws AbsenceWithReservationsException
     */
    public function execute(RegisterAbsenceCommand $command, DateTimeImmutable $now): Absence
    {
        $range = AbsenceRange::fromLocal($command->startDate, $command->endDate, $command->startTime, $command->endTime);
        $absence = Absence::create(
            $this->absences->nextId(),
            new CollaboratorId($command->collaboratorId),
            $range->interval,
            $command->reason === null ? null : new AbsenceReason($command->reason),
            $now,
        );

        $this->transaction->run(function () use ($absence, $now): void {
            $this->collaborators->lockAndCheckActive($absence->collaboratorId->value) || throw AbsenceCollaboratorInactiveException::create();

            $held = $this->conflicts->heldIn($absence->collaboratorId->value, $absence->interval, $now, self::LISTED_RESERVATIONS);

            if ($held->total > 0) {
                throw AbsenceWithReservationsException::with($held->reservations, $held->total);
            }

            $this->absences->save($absence);
        });

        return $absence;
    }
}
