<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Catalog;

use Src\Catalog\Invitations\Application\UseCases\IssueInvitationLink;
use Src\Notifications\Notices\Application\Contracts\CollaboratorInvitations;
use Src\Notifications\Notices\Application\DTOs\CollaboratorInvitationLink;
use Src\Notifications\Notices\Application\DTOs\Recipient;

final readonly class CatalogCollaboratorInvitations implements CollaboratorInvitations
{
    /** Receive the Catalog use case that creates the link of an invitation. */
    public function __construct(private IssueInvitationLink $issue) {}

    /** Ask the invitations for the link and keep only what the notice needs. The person has no account yet, so there is no name to greet. */
    public function issueLink(string $invitationId): ?CollaboratorInvitationLink
    {
        $issued = $this->issue->execute($invitationId);

        return $issued === null ? null : new CollaboratorInvitationLink(new Recipient('', $issued->email), $issued->token, $issued->expiresAt);
    }
}
