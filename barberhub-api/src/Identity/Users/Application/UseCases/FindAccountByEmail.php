<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\AccountSummary;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\ValueObjects\Email;

final readonly class FindAccountByEmail
{
    /** Receive the user repository port. */
    public function __construct(private UserRepository $users) {}

    /** Describe the account registered with the email, or none; never its password. */
    public function execute(string $email): ?AccountSummary
    {
        $user = $this->users->findByEmail(new Email($email));

        return $user === null ? null : new AccountSummary($user->id->value, $user->name->value, $user->email->value);
    }
}
