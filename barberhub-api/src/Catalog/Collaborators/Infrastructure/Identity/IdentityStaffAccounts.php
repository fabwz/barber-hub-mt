<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Infrastructure\Identity;

use SensitiveParameter;
use Src\Catalog\Collaborators\Application\Contracts\StaffAccounts;
use Src\Catalog\Collaborators\Application\DTOs\AccountDescription;
use Src\Identity\Users\Application\DTOs\RegisterStaffAccountCommand;
use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Identity\Users\Application\UseCases\RegisterStaffAccount;

final readonly class IdentityStaffAccounts implements StaffAccounts
{
    /** Receive the Identity use cases that create and describe accounts. */
    public function __construct(
        private RegisterStaffAccount $registerStaffAccount,
        private ListAccountSummaries $listAccountSummaries,
    ) {}

    /** Create the account through the Identity context and return its identifier. */
    public function create(string $name, string $email, #[SensitiveParameter] string $temporaryPassword): string
    {
        return $this->registerStaffAccount
            ->execute(new RegisterStaffAccountCommand($name, $email, $temporaryPassword))
            ->id->value;
    }

    /**
     * Describe the accounts through the Identity context, indexed by identifier.
     *
     * @param  list<string>  $userIds
     * @return array<string, AccountDescription>
     */
    public function describe(array $userIds): array
    {
        $descriptions = [];

        foreach ($this->listAccountSummaries->execute($userIds) as $summary) {
            $descriptions[$summary->userId] = new AccountDescription($summary->name, $summary->email);
        }

        return $descriptions;
    }
}
