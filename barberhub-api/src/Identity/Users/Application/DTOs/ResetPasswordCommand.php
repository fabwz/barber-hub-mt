<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use SensitiveParameter;

final readonly class ResetPasswordCommand
{
    /** Carry the token of the link and the new password. */
    public function __construct(
        #[SensitiveParameter] public string $token,
        #[SensitiveParameter] public string $newPassword,
    ) {}
}
