<?php

declare(strict_types=1);

namespace Tests\Support\Catalog;

use SensitiveParameter;
use Src\Catalog\Collaborators\Application\Contracts\StaffAccounts;
use Src\Catalog\Collaborators\Application\DTOs\AccountDescription;
use Src\Identity\Users\Application\DTOs\RegisterStaffAccountCommand;
use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Identity\Users\Application\UseCases\RegisterStaffAccount;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\InMemoryUserRepository;

/** The staff accounts port answered by the Identity use cases over in-memory accounts, as the adapter does. */
final readonly class InMemoryStaffAccounts implements StaffAccounts
{
    public function __construct(private InMemoryUserRepository $users) {}

    /** Create the account with a temporary password. */
    public function create(string $name, string $email, #[SensitiveParameter] string $temporaryPassword): string
    {
        return new RegisterStaffAccount($this->users, new FakePasswordHasher)
            ->execute(new RegisterStaffAccountCommand($name, $email, $temporaryPassword))
            ->id->value;
    }

    /**
     * Describe the accounts, indexed by identifier.
     *
     * @param  list<string>  $userIds
     * @return array<string, AccountDescription>
     */
    public function describe(array $userIds): array
    {
        $descriptions = [];

        foreach (new ListAccountSummaries($this->users)->execute($userIds) as $summary) {
            $descriptions[$summary->userId] = new AccountDescription($summary->name, $summary->email);
        }

        return $descriptions;
    }
}
