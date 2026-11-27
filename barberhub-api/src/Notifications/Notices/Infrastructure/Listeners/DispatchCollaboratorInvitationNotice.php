<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Listeners;

use Src\Catalog\Invitations\Domain\Events\CollaboratorInvited;
use Src\Notifications\Notices\Infrastructure\Jobs\SendCollaboratorInvitationNoticeJob;

/** Turns the invitation of a collaborator into a task of the queue (RF-06). It does no work of its own, so the request takes no longer for it. */
final class DispatchCollaboratorInvitationNotice
{
    /** Leave the task for the invitation, with its barbershop. */
    public function handle(CollaboratorInvited $event): void
    {
        SendCollaboratorInvitationNoticeJob::dispatch($event->barbershopId, $event->invitationId);
    }
}
