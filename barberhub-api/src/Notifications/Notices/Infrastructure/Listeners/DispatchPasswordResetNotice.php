<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Listeners;

use Src\Identity\Users\Domain\Events\PasswordResetRequested;
use Src\Notifications\Notices\Infrastructure\Jobs\SendPasswordResetNoticeJob;

/** Turns the request for a link into a task of the queue (RF-41). It does no work of its own, so the request takes the same time for every email. */
final class DispatchPasswordResetNotice
{
    /** Leave the task for the email of the request. */
    public function handle(PasswordResetRequested $event): void
    {
        SendPasswordResetNoticeJob::dispatch($event->email);
    }
}
