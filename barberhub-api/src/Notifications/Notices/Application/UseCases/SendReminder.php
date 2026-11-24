<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\UseCases;

use DateTimeImmutable;
use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\Contracts\ReminderPreferences;
use Src\Notifications\Notices\Application\Contracts\ReservationLookup;
use Src\Notifications\Notices\Application\DTOs\NoticeCommand;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Application\NoticeComposer;
use Src\Notifications\Notices\Domain\Contracts\ReminderRepository;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/**
 * Send a reminder when its time comes (RF-33). Nothing is sent when the reservation is no longer confirmed (cancelled, expired, absent),
 * when it no longer starts where the reminder expects, when the customer turned the reminders off or has no email, or when the reminder was
 * already sent or moved (NO-03). It must run inside the transaction of the barbershop: the reminder is claimed first and, if the channel
 * fails, the failure undoes the claim, so the retry of the queue finds it unsent.
 */
final readonly class SendReminder
{
    public function __construct(
        private ReservationLookup $reservations,
        private ReminderPreferences $preferences,
        private NoticeComposer $composer,
        private ReminderRepository $reminders,
        private NoticeChannel $channel,
    ) {}

    /** Send the reminder of the kind that was due at `$scheduledFor`; answers whether it went out. */
    public function execute(string $reservationId, ReminderKind $kind, DateTimeImmutable $scheduledFor, DateTimeImmutable $now): bool
    {
        $reservation = $this->reservations->find($reservationId);

        if ($reservation === null || ! $reservation->confirmed || $reservation->startsAt->modify("-{$kind->hoursBefore()} hours") != $scheduledFor) {
            return false;
        }

        if (! $this->preferences->isEnabledFor($reservation->customerMembershipId)) {
            return false;
        }

        $notice = $this->composer->compose(new NoticeCommand(
            NoticeType::Reminder,
            $reservation->customerMembershipId,
            $reservation->serviceId,
            $reservation->collaboratorId,
            $reservation->startsAt,
            $reservation->endsAt,
            reminder: $kind,
            idempotencyKey: "reminder-{$reservationId}-{$kind->value}-{$scheduledFor->getTimestamp()}",
        ));

        if ($notice === null || ! $this->reminders->claim($reservationId, $kind, $scheduledFor, $now)) {
            return false;
        }

        $this->channel->send($notice);

        return true;
    }
}
