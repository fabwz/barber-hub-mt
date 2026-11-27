<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\Contracts;

use SensitiveParameter;

interface InvitedAccounts
{
    /** The identifier of the account registered with the email, or null when there is none. */
    public function idOfEmail(string $email): ?string;

    /** Create the active account of a person who accepted an invitation, with the password they chose (SE-07), and return its identifier. */
    public function register(string $name, string $email, #[SensitiveParameter] string $password): string;
}
