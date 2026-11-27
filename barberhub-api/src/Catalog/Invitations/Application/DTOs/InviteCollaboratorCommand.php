<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\DTOs;

final readonly class InviteCollaboratorCommand
{
    /**
     * Carry who invites, the email invited and the services the collaborator will offer.
     *
     * @param  list<string>  $serviceIds
     */
    public function __construct(
        public string $invitedByUserId,
        public string $email,
        public array $serviceIds,
    ) {}
}
