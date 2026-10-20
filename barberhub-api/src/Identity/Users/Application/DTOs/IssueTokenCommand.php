<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

final readonly class IssueTokenCommand
{
    /** Carry the credentials, the name of the device that logs in and the address it comes from. */
    public function __construct(
        public string $email,
        public string $password,
        public string $deviceName,
        public string $ipAddress,
    ) {}
}
