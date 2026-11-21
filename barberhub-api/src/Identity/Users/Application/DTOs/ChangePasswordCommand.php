<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use SensitiveParameter;

final readonly class ChangePasswordCommand
{
    /** Carry the person, both passwords and the token of the session that asks, which stays open. */
    public function __construct(
        public string $userId,
        #[SensitiveParameter] public string $currentPassword,
        #[SensitiveParameter] public string $newPassword,
        public string $currentTokenId,
    ) {}
}
