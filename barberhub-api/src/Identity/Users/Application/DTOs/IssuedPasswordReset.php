<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

/** A link to reset a password, ready to be written in an email: the one moment the token exists in plain text. */
final readonly class IssuedPasswordReset
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
