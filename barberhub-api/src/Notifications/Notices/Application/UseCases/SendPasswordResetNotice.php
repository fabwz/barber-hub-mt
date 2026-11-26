<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\UseCases;

use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\Contracts\PasswordResets;
use Src\Notifications\Notices\Application\Contracts\WebLinks;
use Src\Notifications\Notices\Application\DTOs\Branding;
use Src\Notifications\Notices\Application\DTOs\Notice;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Shared\Domain\Contracts\Clock;

/**
 * Write to a person the link to reset their password (RF-41). It never asks for the preference of the reminders: this notice protects the
 * account and cannot be turned off (NO-10). It has no barbershop: the account is global.
 */
final readonly class SendPasswordResetNotice
{
    public function __construct(
        private PasswordResets $resets,
        private WebLinks $links,
        private NoticeChannel $channel,
        private Clock $clock,
    ) {}

    /** Send the link to the email; answers false, sending nothing, when no active account has it. */
    public function execute(string $email): bool
    {
        $reset = $this->resets->issueFor($email);

        if ($reset === null) {
            return false;
        }

        $this->channel->send(new Notice(
            NoticeType::PasswordReset,
            $reset->recipient,
            new Branding(Branding::PLATFORM_NAME, null),
            '',
            '',
            $this->clock->now(),
            $reset->expiresAt,
            $reset->expiresAt,
            null,
            null,
            null,
            $this->links->passwordReset($reset->token),
        ));

        return true;
    }
}
