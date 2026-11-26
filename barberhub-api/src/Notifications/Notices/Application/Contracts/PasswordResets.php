<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use Src\Notifications\Notices\Application\DTOs\PasswordResetLink;

/** Where the link to reset a password comes from: the accounts, which keep only its hash. */
interface PasswordResets
{
    /** Create the link of the account with that email, replacing the one it had; null when there is no active account to write to. */
    public function issueFor(string $email): ?PasswordResetLink;
}
