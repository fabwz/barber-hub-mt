<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure\Mail;

use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Mail;
use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\DTOs\Notice;

final readonly class MailNoticeChannel implements NoticeChannel
{
    /**
     * Send the notice as an email, right now: the task of the queue that calls it is already the queue, so a failure is the failure of that
     * task and its retries, not of a second one. The mailer is the one of the configuration (`log` or `array` in development and tests, `resend` in the deployment).
     */
    public function send(Notice $notice): void
    {
        Mail::to(new Address($notice->recipient->email, $notice->recipient->name))->send(new NoticeMail($notice));
    }
}
