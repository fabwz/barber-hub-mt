<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\DTOs;

use DateTimeImmutable;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/**
 * A notice ready to be delivered by a channel: who it is for and everything its text needs. `$actionUrl` is the address the notice invites to
 * open, only for the notices of the platform (the link to reset a password); it holds a secret, so it is never written to a log.
 */
final readonly class Notice
{
    public function __construct(
        public NoticeType $type,
        public Recipient $recipient,
        public Branding $branding,
        public string $serviceName,
        public string $collaboratorName,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $previousStartsAt,
        public ?ReminderKind $reminder,
        public ?string $idempotencyKey,
        public ?string $actionUrl = null,
    ) {}
}
