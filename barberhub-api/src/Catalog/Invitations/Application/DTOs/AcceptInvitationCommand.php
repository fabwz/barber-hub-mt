<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\DTOs;

use SensitiveParameter;

final readonly class AcceptInvitationCommand
{
    /** Carry the token of the link and, for an email with no account yet, the name and the password the person chose. */
    public function __construct(
        #[SensitiveParameter] public string $token,
        public ?string $name,
        #[SensitiveParameter] public ?string $password,
    ) {}
}
