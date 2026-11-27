<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use SensitiveParameter;

final readonly class RegisterInvitedAccountCommand
{
    /** Carry the data of the account of a person who accepted an invitation, with the password they chose. */
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $password,
    ) {}
}
