<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use SensitiveParameter;

final readonly class RegisterStaffAccountCommand
{
    /** Carry the data needed to create the account of a member of the staff with a temporary password. */
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $temporaryPassword,
    ) {}
}
