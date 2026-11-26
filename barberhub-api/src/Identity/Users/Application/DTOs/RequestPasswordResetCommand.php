<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

final readonly class RequestPasswordResetCommand
{
    /** Carry the email that asks for the link and the address the request comes from. */
    public function __construct(
        public string $email,
        public string $ipAddress,
    ) {}
}
