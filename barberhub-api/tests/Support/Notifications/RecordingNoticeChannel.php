<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use RuntimeException;
use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\DTOs\Notice;

/** A channel that keeps what it was asked to send, and can be told to fail, as a provider that answers 429 or is down does. */
final class RecordingNoticeChannel implements NoticeChannel
{
    /** @var list<Notice> */
    public array $sent = [];

    public int $attempts = 0;

    public bool $failing = false;

    /** Keep the notice, or fail when the test says so. */
    public function send(Notice $notice): void
    {
        $this->attempts++;

        if ($this->failing) {
            throw new RuntimeException('The provider refused the message.');
        }

        $this->sent[] = $notice;
    }
}
