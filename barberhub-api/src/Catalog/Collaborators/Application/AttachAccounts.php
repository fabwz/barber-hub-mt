<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application;

use Src\Catalog\Collaborators\Application\Contracts\StaffAccounts;
use Src\Catalog\Collaborators\Application\DTOs\CollaboratorWithAccount;
use Src\Catalog\Collaborators\Domain\Entities\Collaborator;

final readonly class AttachAccounts
{
    /** Receive the staff accounts port. */
    public function __construct(private StaffAccounts $accounts) {}

    /**
     * Pair each collaborator with the name and email of their account, in the same order.
     *
     * @param  list<Collaborator>  $collaborators
     * @return list<CollaboratorWithAccount>
     */
    public function to(array $collaborators): array
    {
        $accounts = $this->accounts->describe(array_map(fn (Collaborator $collaborator): string => $collaborator->userId->value, $collaborators));

        return array_map(
            fn (Collaborator $collaborator): CollaboratorWithAccount => new CollaboratorWithAccount(
                $collaborator,
                $accounts[$collaborator->userId->value]->name ?? '',
                $accounts[$collaborator->userId->value]->email ?? '',
            ),
            $collaborators,
        );
    }

    /** Pair one collaborator with the name and email of their account. */
    public function toOne(Collaborator $collaborator): CollaboratorWithAccount
    {
        return $this->to([$collaborator])[0];
    }
}
