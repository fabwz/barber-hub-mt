<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

final readonly class UpdateAccountCommand
{
    /** Carry the person and the corrected name, email and phone. */
    public function __construct(
        public string $userId,
        public string $name,
        public string $email,
        public string $phone,
    ) {}
}
