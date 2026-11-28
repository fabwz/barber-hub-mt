<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Identity;

use Src\Catalog\Barbershops\Application\Contracts\OwnerAccounts;
use Src\Catalog\Barbershops\Application\DTOs\OwnerAccount;
use Src\Identity\Users\Application\DTOs\ProvideOwnerAccountCommand;
use Src\Identity\Users\Application\UseCases\ProvideOwnerAccount;

final readonly class IdentityOwnerAccounts implements OwnerAccounts
{
    /** Receive the Identity use case that finds or creates the account of an owner. */
    public function __construct(private ProvideOwnerAccount $provideOwnerAccount) {}

    /** Ask Identity for the account of the owner. */
    public function provide(string $name, string $email, string $phone): OwnerAccount
    {
        $account = $this->provideOwnerAccount->execute(new ProvideOwnerAccountCommand($name, $email, $phone));

        return new OwnerAccount($account->userId, $account->email, $account->temporaryPassword);
    }
}
