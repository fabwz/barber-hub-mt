<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application;

use Src\Catalog\Collaborators\Application\Contracts\StaffAccounts;
use Src\Catalog\Collaborators\Application\Contracts\StaffMemberships;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;
use Src\Shared\Domain\Contracts\BarbershopContext;

final readonly class AttachAccounts
{
    /** Receive the staff accounts and memberships ports and the barbershop context. */
    public function __construct(
        private StaffAccounts $accounts,
        private StaffMemberships $memberships,
        private BarbershopContext $context,
    ) {}

    /**
     * Pair each collaborator with the name and email of their account and with whether that account is the owner of the
     * barbershop (CO-02), in the same order.
     *
     * @param  list<Collaborator>  $collaborators
     * @return list<CollaboratorWithAccount>
     */
    public function to(array $collaborators): array
    {
        $userIds = array_map(fn (Collaborator $collaborator): string => $collaborator->userId->value, $collaborators);
        $accounts = $this->accounts->describe($userIds);
        $owners = $userIds === [] ? [] : $this->memberships->ownersAmong($this->context->current(), $userIds);

        return array_map(
            fn (Collaborator $collaborator): CollaboratorWithAccount => new CollaboratorWithAccount(
                $collaborator,
                $accounts[$collaborator->userId->value]->name ?? '',
                $accounts[$collaborator->userId->value]->email ?? '',
                in_array($collaborator->userId->value, $owners, true),
            ),
            $collaborators,
        );
    }

    /** Pair one collaborator with the name and email of their account and with whether it is the owner. */
    public function toOne(Collaborator $collaborator): CollaboratorWithAccount
    {
        return $this->to([$collaborator])[0];
    }
}
