<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use DateTimeImmutable;
use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\Contracts\StaffMemberships;
use Src\Catalog\Collaborators\Application\Contracts\UpcomingReservations;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorHasUpcomingReservationsException;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class SetCollaboratorActive
{
    /** How many of the reservations that block a deactivation the error lists; it also tells how many there are in all. */
    public const int LISTED_RESERVATIONS = 20;

    /** Receive the collaborator repository, the ports toward Identity and Reservations, the barbershop context and the transaction. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private StaffMemberships $memberships,
        private BarbershopContext $context,
        private TransactionRunner $transaction,
        private AttachAccounts $attachAccounts,
        private UpcomingReservations $upcoming,
    ) {}

    /**
     * Activate or deactivate a collaborator and, with them, the collaborator role of their account. An owner who also
     * has a profile keeps their membership (UP-05). A collaborator with confirmed reservations ahead is not deactivated
     * until the owner reassigns or cancels them (CO-03): the error lists the nearest ones. Activating is always allowed.
     * The row of the collaborator is locked first, so a reservation made at the same time either shows up in the check or
     * waits until the deactivation is done, and then finds a collaborator that is not bookable.
     *
     * @throws CollaboratorNotFoundException
     * @throws CollaboratorHasUpcomingReservationsException
     */
    public function execute(string $collaboratorId, bool $active, DateTimeImmutable $now): CollaboratorWithAccount
    {
        $collaborator = $this->collaborators->find(new CollaboratorId($collaboratorId))
            ?? throw CollaboratorNotFoundException::create();

        $this->transaction->run(function () use ($collaborator, $active, $now): void {
            if ($active) {
                $collaborator->activate();
                $this->memberships->activateCollaboratorRole($this->context->current(), $collaborator->userId->value);
            } else {
                $this->refuseWhileReservationsRemain($collaborator, $now);
                $collaborator->deactivate();
                $this->memberships->deactivateCollaboratorRole($this->context->current(), $collaborator->userId->value);
            }

            $this->collaborators->save($collaborator);
        });

        return $this->attachAccounts->toOne($collaborator);
    }

    /** Refuse naming the confirmed reservations that start from now on; one already inactive has nothing left to check. */
    private function refuseWhileReservationsRemain(Collaborator $collaborator, DateTimeImmutable $now): void
    {
        if (! $collaborator->active) {
            return;
        }

        $this->collaborators->lock($collaborator->id) || throw CollaboratorNotFoundException::create();
        $upcoming = $this->upcoming->confirmedOf($collaborator->id->value, $now, self::LISTED_RESERVATIONS);

        if ($upcoming->total > 0) {
            throw CollaboratorHasUpcomingReservationsException::with($upcoming->reservations, $upcoming->total);
        }
    }
}
