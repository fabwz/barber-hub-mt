<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Src\Notifications\Notices\Application\UseCases\SendCollaboratorInvitationNotice;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;

/**
 * Sends the link of the invitation of a collaborator (RF-06). It is left in the queue only after the transaction that made the invitation
 * commits, so the worker never looks for a row that does not exist yet. It carries the barbershop and the invitation and never the token: the
 * token is born inside `handle`, from the invitation, and goes straight to the email. A retry creates a new link, which replaces the old.
 * The task is encrypted anyway, because it travels with the identifiers of a barbershop and nothing about it should be readable in the queue.
 */
final class SendCollaboratorInvitationNoticeJob implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use ThrottledNoticeJob;

    public function __construct(
        public readonly string $barbershopId,
        public readonly string $invitationId,
    ) {}

    /** Create the link and send it, as the barbershop of the invitation. */
    public function handle(BarbershopProcessScope $scope, SendCollaboratorInvitationNotice $send): void
    {
        $scope->run($this->barbershopId, fn (): bool => $send->execute($this->invitationId));
    }
}
