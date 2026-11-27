<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

/** The link of an invitation, ready to be written in an email: the one moment the token exists in plain text. */
final readonly class IssuedInvitationLink
{
    public function __construct(
        public string $email,
        #[SensitiveParameter] public string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
