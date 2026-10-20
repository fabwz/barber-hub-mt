<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\Contracts\StaffMemberships;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Catalog\Collaborators\Domain\ValueObjects\CollaboratorId;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\TransactionRunner;

final readonly class SetCollaboratorActive
{
    /** Receive the collaborator repository, the memberships port, the barbershop context and the transaction. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private StaffMemberships $memberships,
        private BarbershopContext $context,
        private TransactionRunner $transaction,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * Activate or deactivate a collaborator and, with them, the collaborator role of their account. An owner who also
     * has a profile keeps their membership (UP-05).
     *
     * TODO(#126, #56): deactivating must be refused while the collaborator has confirmed future reservations, until the
     * owner reassigns or cancels them (CO-03). Reservations do not exist yet, so there is nothing to check.
     *
     * @throws CollaboratorNotFoundException
     */
    public function execute(string $collaboratorId, bool $active): CollaboratorWithAccount
    {
        $collaborator = $this->collaborators->find(new CollaboratorId($collaboratorId))
            ?? throw CollaboratorNotFoundException::create();

        $this->transaction->run(function () use ($collaborator, $active): void {
            if ($active) {
                $collaborator->activate();
                $this->memberships->activateCollaboratorRole($this->context->current(), $collaborator->userId->value);
            } else {
                $collaborator->deactivate();
                $this->memberships->deactivateCollaboratorRole($this->context->current(), $collaborator->userId->value);
            }

            $this->collaborators->save($collaborator);
        });

        return $this->attachAccounts->toOne($collaborator);
    }
}
