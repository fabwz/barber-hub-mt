<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

use SensitiveParameter;

final readonly class OwnerAccount
{
    /** The account of the owner, with its email as the account keeps it, and, only when it was created now, its temporary password. */
    public function __construct(
        public string $userId,
        public string $email,
        #[SensitiveParameter] public ?string $temporaryPassword,
    ) {}

    /** Whether the account was created by the registration: an account that already existed has no password to show. */
    public function created(): bool
    {
        return $this->temporaryPassword !== null;
    }
}
