<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Resend;
use Src\Catalog\Invitations\Domain\Events\CollaboratorInvited;
use Src\Identity\Users\Domain\Events\PasswordResetRequested;
use Src\Notifications\Notices\Application\Contracts\AssistedRecipients;
use Src\Notifications\Notices\Application\Contracts\BarbershopBranding;
use Src\Notifications\Notices\Application\Contracts\CatalogNames;
use Src\Notifications\Notices\Application\Contracts\CollaboratorInvitations;
use Src\Notifications\Notices\Application\Contracts\NoticeChannel;
use Src\Notifications\Notices\Application\Contracts\PasswordResets;
use Src\Notifications\Notices\Application\Contracts\Recipients;
use Src\Notifications\Notices\Application\Contracts\ReminderJobs;
use Src\Notifications\Notices\Application\Contracts\ReminderPreferences;
use Src\Notifications\Notices\Application\Contracts\ReservationLookup;
use Src\Notifications\Notices\Application\Contracts\WebLinks;
use Src\Notifications\Notices\Domain\Contracts\ReminderRepository;
use Src\Notifications\Notices\Infrastructure\AssistedClients\AssistedClientsAssistedRecipients;
use Src\Notifications\Notices\Infrastructure\Catalog\CatalogBarbershopBranding;
use Src\Notifications\Notices\Infrastructure\Catalog\CatalogCollaboratorInvitations;
use Src\Notifications\Notices\Infrastructure\Catalog\CatalogNoticeNames;
use Src\Notifications\Notices\Infrastructure\Identity\IdentityPasswordResets;
use Src\Notifications\Notices\Infrastructure\Identity\IdentityRecipients;
use Src\Notifications\Notices\Infrastructure\Identity\IdentityReminderPreferences;
use Src\Notifications\Notices\Infrastructure\Jobs\SendNoticeJob;
use Src\Notifications\Notices\Infrastructure\Listeners\DispatchCollaboratorInvitationNotice;
use Src\Notifications\Notices\Infrastructure\Listeners\DispatchNotices;
use Src\Notifications\Notices\Infrastructure\Listeners\DispatchPasswordResetNotice;
use Src\Notifications\Notices\Infrastructure\Listeners\ScheduleRemindersOnReservation;
use Src\Notifications\Notices\Infrastructure\Mail\IdempotentResendTransport;
use Src\Notifications\Notices\Infrastructure\Mail\MailNoticeChannel;
use Src\Notifications\Notices\Infrastructure\Persistence\Repositories\EloquentReminderRepository;
use Src\Notifications\Notices\Infrastructure\Queue\QueuedReminders;
use Src\Notifications\Notices\Infrastructure\Reservations\ReservationsReservationLookup;
use Src\Notifications\Notices\Infrastructure\Web\ConfiguredWebLinks;
use Src\Reservations\Reservations\Domain\Events\ReservationCancelled;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationRescheduled;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;

final class NoticesServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ReminderRepository::class => EloquentReminderRepository::class,
        NoticeChannel::class => MailNoticeChannel::class,
        ReminderJobs::class => QueuedReminders::class,
        ReservationLookup::class => ReservationsReservationLookup::class,
        Recipients::class => IdentityRecipients::class,
        AssistedRecipients::class => AssistedClientsAssistedRecipients::class,
        PasswordResets::class => IdentityPasswordResets::class,
        ReminderPreferences::class => IdentityReminderPreferences::class,
        BarbershopBranding::class => CatalogBarbershopBranding::class,
        CatalogNames::class => CatalogNoticeNames::class,
        CollaboratorInvitations::class => CatalogCollaboratorInvitations::class,
    ];

    /** The address of the web client, which the links of the emails point to, comes from the configuration where the deployment sets it. */
    public function register(): void
    {
        $this->app->bind(WebLinks::class, fn (): WebLinks => new ConfiguredWebLinks(config()->string('notices.web_url')));
    }

    /**
     * Listen to the events that the customer must hear about (RF-33, RF-34), keep the sending to the rate limit of the provider and
     * make the `resend` mailer the one that carries the idempotency key of each send.
     */
    public function boot(): void
    {
        Event::listen(PasswordResetRequested::class, DispatchPasswordResetNotice::class);
        Event::listen(CollaboratorInvited::class, DispatchCollaboratorInvitationNotice::class);
        Event::listen(ReservationConfirmed::class, [DispatchNotices::class, 'onConfirmed']);
        Event::listen(OfferCreated::class, [DispatchNotices::class, 'onOffered']);
        Event::listen(ReservationCancelled::class, [DispatchNotices::class, 'onCancelled']);
        Event::listen(ReservationRescheduled::class, [DispatchNotices::class, 'onRescheduled']);
        Event::listen([ReservationConfirmed::class, ReservationRescheduled::class], ScheduleRemindersOnReservation::class);

        RateLimiter::for(SendNoticeJob::LIMITER, fn (): Limit => Limit::perSecond(max(1, config()->integer('notices.mail_per_second'))));

        $this->callAfterResolving(MailManager::class, function (MailManager $mail): void {
            $mail->extend('resend', fn (array $config): IdempotentResendTransport => new IdempotentResendTransport(
                Resend::client((string) ($config['key'] ?? config('services.resend.key'))),
            ));
        });
    }
}
