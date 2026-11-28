<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use SensitiveParameter;

final readonly class ProvidedOwnerAccount
{
    /** Say which account is the owner, under which normalised email and, only when it was just created, the temporary password to hand over once. */
    public function __construct(
        public string $userId,
        public string $email,
        #[SensitiveParameter] public ?string $temporaryPassword,
    ) {}

    /** Whether the account was created now: an account that already existed keeps its password and has none to show. */
    public function created(): bool
    {
        return $this->temporaryPassword !== null;
    }
}
