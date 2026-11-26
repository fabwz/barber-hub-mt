<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\DTOs;

use DateTimeImmutable;
use SensitiveParameter;

/** The person who asked to reset their password and the secret of their link, as the notice needs them; the token lives only in memory and in the email. */
final readonly class PasswordResetLink
{
    public function __construct(
        public Recipient $recipient,
        #[SensitiveParameter] public string $token,
        public DateTimeImmutable $expiresAt,
    ) {}
}
