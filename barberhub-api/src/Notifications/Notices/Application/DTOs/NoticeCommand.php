<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\DTOs;

use DateTimeImmutable;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/** The facts of one notice, in primitive data, as the task of the queue carries them. */
final readonly class NoticeCommand
{
    /**
     * `$expiresAt` is only for an offer (when it stops being valid), `$previousStartsAt` only for a reschedule (the start the reservation had)
     * and `$reminder` only for a reminder. The notice is for the customer role, or for the assisted client when the reservation was registered by the owner (RF-12). `$idempotencyKey` is a key a channel may use so that a message already delivered is not delivered again.
     */
    public function __construct(
        public NoticeType $type,
        public ?string $customerMembershipId,
        public string $serviceId,
        public string $collaboratorId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $previousStartsAt = null,
        public ?ReminderKind $reminder = null,
        public ?string $idempotencyKey = null,
        public ?string $assistedClientId = null,
    ) {}
}
