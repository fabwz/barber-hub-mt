<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Infrastructure\Identity;

use SensitiveParameter;
use Src\Catalog\Invitations\Application\Contracts\InvitedAccounts;
use Src\Identity\Users\Application\DTOs\RegisterInvitedAccountCommand;
use Src\Identity\Users\Application\UseCases\FindAccountByEmail;
use Src\Identity\Users\Application\UseCases\RegisterInvitedAccount;

final readonly class IdentityInvitedAccounts implements InvitedAccounts
{
    /** Receive the Identity use cases that find and create accounts. */
    public function __construct(
        private FindAccountByEmail $findAccount,
        private RegisterInvitedAccount $registerAccount,
    ) {}

    /** Ask Identity for the account of the email. */
    public function idOfEmail(string $email): ?string
    {
        return $this->findAccount->execute($email)?->userId;
    }

    /** Create the account through the Identity context and return its identifier. */
    public function register(string $name, string $email, #[SensitiveParameter] string $password): string
    {
        return $this->registerAccount->execute(new RegisterInvitedAccountCommand($name, $email, $password));
    }
}
