<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Src\Notifications\LiveUpdates\Application\Contracts\AgendaCollaborators;
use Src\Notifications\LiveUpdates\Application\Contracts\MembershipUsers;
use Src\Notifications\LiveUpdates\Infrastructure\Collaborators\CollaboratorsAgendaCollaborators;
use Src\Notifications\LiveUpdates\Infrastructure\Identity\IdentityMembershipUsers;
use Src\Notifications\LiveUpdates\Infrastructure\Listeners\PublishAgendaChanges;
use Src\Notifications\LiveUpdates\Infrastructure\Listeners\PublishOfferChanges;
use Src\Reservations\Reservations\Domain\Events\ReservationCancelled;
use Src\Reservations\Reservations\Domain\Events\ReservationClosed;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationCreated;
use Src\Reservations\Reservations\Domain\Events\ReservationExpired;
use Src\Reservations\Reservations\Domain\Events\ReservationRescheduled;
use Src\Waitlist\Offers\Domain\Events\OfferAnswered;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;
use Src\Waitlist\Offers\Domain\Events\OfferExpired;

final class LiveUpdatesServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AgendaCollaborators::class => CollaboratorsAgendaCollaborators::class,
        MembershipUsers::class => IdentityMembershipUsers::class,
    ];

    /** Listen to every change of a reservation to publish it in the live agenda (RF-36) and limit the authorizations of the channels to 60 a minute per person. */
    public function boot(): void
    {
        Event::listen(ReservationCreated::class, [PublishAgendaChanges::class, 'onCreated']);
        Event::listen(ReservationConfirmed::class, [PublishAgendaChanges::class, 'onConfirmed']);
        Event::listen(ReservationRescheduled::class, [PublishAgendaChanges::class, 'onRescheduled']);
        Event::listen(ReservationCancelled::class, [PublishAgendaChanges::class, 'onCancelled']);
        Event::listen(ReservationClosed::class, [PublishAgendaChanges::class, 'onClosed']);
        Event::listen(ReservationExpired::class, [PublishAgendaChanges::class, 'onExpired']);
        Event::listen(OfferCreated::class, [PublishOfferChanges::class, 'onCreated']);
        Event::listen(OfferExpired::class, [PublishOfferChanges::class, 'onExpired']);
        Event::listen(OfferAnswered::class, [PublishOfferChanges::class, 'onAnswered']);

        RateLimiter::for('live-authorizations', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
