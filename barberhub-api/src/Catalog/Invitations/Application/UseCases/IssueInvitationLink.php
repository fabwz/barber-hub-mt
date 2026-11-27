<?php

declare(strict_types=1);

namespace Src\Catalog\Invitations\Application\UseCases;

use Src\Catalog\Invitations\Application\DTOs\IssuedInvitationLink;
use Src\Catalog\Invitations\Domain\Contracts\InvitationRepository;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationId;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationToken;
use Src\Shared\Domain\Contracts\Clock;

final readonly class IssueInvitationLink
{
    /** Receive the invitation repository and the clock. */
    public function __construct(private InvitationRepository $invitations, private Clock $clock) {}

    /**
     * Create the link of a pending invitation, over the one it had, and answer it with its token, which is the only time the token exists in
     * plain text: the caller writes it in the email. The invitation keeps the expiry it got when it was made (UP-04). Null when it was
     * cancelled, used or has expired in the meantime, so nothing is sent for it.
     */
    public function execute(string $invitationId): ?IssuedInvitationLink
    {
        $token = InvitationToken::generate();
        $invitation = $this->invitations->replaceLink(new InvitationId($invitationId), $token->hash(), $this->clock->now());

        return $invitation === null ? null : new IssuedInvitationLink($invitation->email, $token->plain, $invitation->expiresAt);
    }
}
