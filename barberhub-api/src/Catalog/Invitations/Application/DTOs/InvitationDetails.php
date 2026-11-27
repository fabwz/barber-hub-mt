<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\DTOs;

/** What the person sees before accepting: who invites, the email, and whether they need to create an account or already have one. */
final readonly class InvitationDetails
{
    public function __construct(
        public string $barbershopName,
        public string $email,
        public bool $accountExists,
    ) {}
}
