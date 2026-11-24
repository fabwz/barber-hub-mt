<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\UseCases;

use DateTimeImmutable;
use Src\Notifications\Notices\Application\Contracts\Recipients;
use Src\Notifications\Notices\Application\Contracts\ReminderJobs;
use Src\Notifications\Notices\Application\Contracts\ReminderPreferences;
use Src\Notifications\Notices\Domain\Contracts\ReminderRepository;
use Src\Notifications\Notices\Domain\Services\ReminderSchedule;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/**
 * Leave the reminders of a reservation for their time (RF-33, NO-02). It runs when the reservation is confirmed and again when it is
 * rescheduled: the reminders that still fit are moved to the new hours and become unsent, the ones that no longer fit are forgotten. Running
 * it twice for the same hours changes nothing, so a repeated event never makes a sent reminder due again (NO-03).
 */
final readonly class ScheduleReminders
{
    public function __construct(
        private ReminderRepository $reminders,
        private ReminderJobs $jobs,
        private Recipients $recipients,
        private ReminderPreferences $preferences,
    ) {}

    /** Schedule the reminders of the reservation that starts at `$startsAt`, seen from `$now`. */
    public function execute(string $reservationId, string $customerMembershipId, DateTimeImmutable $startsAt, DateTimeImmutable $now): void
    {
        $wanted = $this->recipients->ofMembership($customerMembershipId) !== null && $this->preferences->isEnabledFor($customerMembershipId);
        $due = [];

        foreach ($wanted ? ReminderSchedule::from($startsAt, $now) : [] as $reminder) {
            $due[$reminder->kind->value] = $reminder->at;
        }

        foreach (ReminderKind::cases() as $kind) {
            $at = $due[$kind->value] ?? null;

            if ($at === null) {
                $this->reminders->forget($reservationId, $kind);

                continue;
            }

            if ($this->reminders->schedule($reservationId, $kind, $at)) {
                $this->jobs->schedule($reservationId, $kind, $at);
            }
        }
    }
}
