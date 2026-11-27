<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\UseCases;

use Src\Notifications\Notices\Application\Contracts\BarbershopBranding;
use Src\Notifications\Notices\Application\Contracts\CollaboratorInvitations;
use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\Contracts\WebLinks;
use Src\Notifications\Notices\Application\DTOs\Notice;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Shared\Domain\Contracts\Clock;

/**
 * Write to a person the link of the invitation the owner sent them (RF-06), in the name of the barbershop. It never asks for a preference: the
 * person has no account to set one in and the owner asked for it (NO-10). It runs inside the barbershop of the invitation.
 */
final readonly class SendCollaboratorInvitationNotice
{
    public function __construct(
        private CollaboratorInvitations $invitations,
        private BarbershopBranding $branding,
        private WebLinks $links,
        private NoticeChannel $channel,
        private Clock $clock,
    ) {}

    /** Send the link to the invited email; answers false, sending nothing, when the invitation is no longer pending. */
    public function execute(string $invitationId): bool
    {
        $link = $this->invitations->issueLink($invitationId);

        if ($link === null) {
            return false;
        }

        $this->channel->send(new Notice(
            NoticeType::CollaboratorInvitation,
            $link->recipient,
            $this->branding->current(),
            '',
            '',
            $this->clock->now(),
            $link->expiresAt,
            $link->expiresAt,
            null,
            null,
            null,
            $this->links->invitationAcceptance($link->token),
        ));

        return true;
    }
}
