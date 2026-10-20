<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\UseCases;

use Src\Catalog\Collaborators\Application\AttachAccounts;
use Src\Catalog\Collaborators\Application\Contracts\StaffAccounts;
use Src\Catalog\Collaborators\Application\Contracts\StaffMemberships;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Application\DTOs\CreateCollaboratorCommand;
use Src\Catalog\Collaborators\Domain\Contracts\CollaboratorRepository;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class CreateCollaboratorWithAccount
{
    /** Receive the collaborator repository, the ports toward Identity, the barbershop context and the transaction. */
    public function __construct(
        private CollaboratorRepository $collaborators,
        private StaffAccounts $accounts,
        private StaffMemberships $memberships,
        private BarbershopContext $context,
        private TransactionRunner $transaction,
        private AttachAccounts $attachAccounts,
    ) {}

    /**
     * Create the account, the collaborator role and the profile together, so none exists without the others (RF-05, UP-04).
     * An email that already has an account is refused: linking it is the invitation flow (RF-06).
     */
    public function execute(CreateCollaboratorCommand $command): CollaboratorWithAccount
    {
        return $this->transaction->run(function () use ($command): CollaboratorWithAccount {
            $userId = $this->accounts->create($command->name, $command->email, $command->temporaryPassword);
            $this->memberships->grantCollaboratorRole($this->context->current(), $userId);

            $collaborator = Collaborator::create($this->collaborators->nextId(), new UserId($userId));
            $this->collaborators->save($collaborator);

            return $this->attachAccounts->toOne($collaborator);
        });
    }
}
