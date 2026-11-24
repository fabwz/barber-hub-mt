<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\Contracts;

use Src\Notifications\Notices\Application\DTOs\Notice;

/**
 * The way a notice reaches the customer. The email is the first channel; another one (WhatsApp, an extra) implements this same port and
 * the use cases do not change. A channel that fails throws, so that the task of the queue retries.
 */
interface NoticeChannel
{
    /** Deliver the notice to its recipient. */
    public function send(Notice $notice): void;
}
