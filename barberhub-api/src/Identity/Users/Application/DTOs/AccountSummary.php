<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

final readonly class AccountSummary
{
    /** Carry what another context may show about an account: never the password. */
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
    ) {}
}
