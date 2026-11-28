<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use SensitiveParameter;

final readonly class RegisterPlatformOperatorCommand
{
    /** Carry the data needed to create the account of a platform operator with a temporary password. */
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $temporaryPassword,
    ) {}
}
