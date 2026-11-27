<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use Src\Notifications\Notices\Application\DTOs\CollaboratorInvitationLink;

/** Where the link of an invitation comes from: the invitations, which keep only its hash. */
interface CollaboratorInvitations
{
    /** Create the link of the invitation of the current barbershop, replacing the one it had; null when it is no longer pending. */
    public function issueLink(string $invitationId): ?CollaboratorInvitationLink;
}
