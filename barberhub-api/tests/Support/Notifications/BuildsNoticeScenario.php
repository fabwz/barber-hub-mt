<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use DateTimeImmutable;
use DateTimeZone;
use Src\Notifications\Notices\Application\Contracts\AssistedRecipients;
use Src\Notifications\Notices\Application\Contracts\BarbershopBranding;
use Src\Notifications\Notices\Application\Contracts\CatalogNames;
use Src\Notifications\Notices\Application\Contracts\Recipients;
use Src\Notifications\Notices\Application\CustomerRecipient;
use Src\Notifications\Notices\Application\DTOs\Branding;
use Src\Notifications\Notices\Application\DTOs\Recipient;
use Src\Notifications\Notices\Application\DTOs\ReservationFacts;
use Src\Notifications\Notices\Application\NoticeComposer;
use Src\Notifications\Notices\Application\UseCases\ScheduleReminders;
use Src\Notifications\Notices\Application\UseCases\SendNotice;
use Src\Notifications\Notices\Application\UseCases\SendReminder;

/**
 * The use cases of the notices wired to doubles, for the tests that need no framework or database. One customer role (Ana, with email) and
 * one without email (a customer created by the barbershop, CL-06); the reservations are told to the lookup with `reservation()`.
 */
trait BuildsNoticeScenario
{
    private const string ANA = 'membership-ana';

    private const string WALK_IN = 'membership-walk-in';

    private const string RESERVATION = 'reservation-1';

    private InMemoryReminderRepository $reminders;

    private RecordingNoticeChannel $channel;

    private RecordingReminderJobs $jobs;

    private ChosenReminderPreferences $preferences;

    private KnownReservations $reservations;

    private ScheduleReminders $scheduleReminders;

    private SendReminder $sendReminder;

    private SendNotice $sendNotice;

    /** Build the use cases with their doubles. */
    private function buildNoticeScenario(): void
    {
        $this->reminders = new InMemoryReminderRepository;
        $this->channel = new RecordingNoticeChannel;
        $this->jobs = new RecordingReminderJobs;
        $this->preferences = new ChosenReminderPreferences;
        $this->reservations = new KnownReservations;

        $recipients = new class implements Recipients
        {
            public function ofMembership(string $membershipId): ?Recipient
            {
                return $membershipId === 'membership-ana' ? new Recipient('Ana Rojas', 'ana@barberhub.test') : null;
            }
        };
        $assisted = new class implements AssistedRecipients
        {
            public function ofAssistedClient(string $assistedClientId): ?Recipient
            {
                return $assistedClientId === 'assisted-rosa' ? new Recipient('Rosa Campos', 'rosa@barberhub.test') : null;
            }
        };
        $branding = new class implements BarbershopBranding
        {
            public function current(): Branding
            {
                return new Branding('Pura Navaja', 'http://localhost/api/v1/archivos/barbershops/b/logo/l.png');
            }
        };
        $names = new class implements CatalogNames
        {
            public function serviceName(string $serviceId): string
            {
                return 'Degradado';
            }

            public function collaboratorName(string $collaboratorId): string
            {
                return 'Daniela';
            }
        };

        $recipient = new CustomerRecipient($recipients, $assisted);
        $composer = new NoticeComposer($recipient, $branding, $names);
        $this->scheduleReminders = new ScheduleReminders($this->reminders, $this->jobs, $recipient, $this->preferences);
        $this->sendReminder = new SendReminder($this->reservations, $this->preferences, $composer, $this->reminders, $this->channel);
        $this->sendNotice = new SendNotice($composer, $this->channel);
    }

    /** A moment of Costa Rica, in March 2030. */
    private static function at(string $day, string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-{$day} {$time}:00", new DateTimeZone('America/Costa_Rica'));
    }

    /** Tell the lookup about a confirmed (or not) reservation that starts at the instant. */
    private function reservation(DateTimeImmutable $startsAt, bool $confirmed = true, ?string $membership = self::ANA, string $id = self::RESERVATION, ?string $assistedClient = null): void
    {
        $this->reservations->items[$id] = new ReservationFacts($confirmed, $startsAt, $startsAt->modify('+45 minutes'), $membership, 'service-1', 'collaborator-1', $assistedClient);
    }
}
