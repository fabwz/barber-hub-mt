<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Src\Notifications\Notices\Application\UseCases\SendPasswordResetNotice;

/**
 * Sends the link to reset a password (RF-41). The request only leaves this task, with the email, for an existing account or not, so the
 * answer to the request is the same; here, away from it, the link is created and written. The task is encrypted because it holds an email, and
 * it never holds the token: the token is born inside `handle` and goes straight to the email. A retry creates a new link, which replaces the old.
 */
final class SendPasswordResetNoticeJob implements ShouldBeEncrypted, ShouldQueue
{
    use ThrottledNoticeJob;

    public function __construct(public readonly string $email) {}

    /** Create the link and send it, if the email has an active account. */
    public function handle(SendPasswordResetNotice $send): void
    {
        $send->execute($this->email);
    }
}
