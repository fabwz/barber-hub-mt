<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\UseCases;

use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\DTOs\NoticeCommand;
use Src\Notifications\Notices\Application\NoticeComposer;

/** Tell the customer that their reservation was confirmed, that a space was offered to them, or that the barbershop cancelled or moved it (RF-34). */
final readonly class SendNotice
{
    public function __construct(
        private NoticeComposer $composer,
        private NoticeChannel $channel,
    ) {}

    /** Deliver the notice; answers false, sending nothing, when the customer has no email (CL-06). */
    public function execute(NoticeCommand $command): bool
    {
        $notice = $this->composer->compose($command);

        if ($notice === null) {
            return false;
        }

        $this->channel->send($notice);

        return true;
    }
}
