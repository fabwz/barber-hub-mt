<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Src\Notifications\LiveUpdates\Application\Contracts\AgendaCollaborators;
use Src\Notifications\LiveUpdates\Infrastructure\Collaborators\CollaboratorsAgendaCollaborators;
use Src\Notifications\LiveUpdates\Infrastructure\Listeners\PublishAgendaChanges;
use Src\Reservations\Reservations\Domain\Events\ReservationCancelled;
use Src\Reservations\Reservations\Domain\Events\ReservationClosed;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\ReservationCreated;
use Src\Reservations\Reservations\Domain\Events\ReservationExpired;
use Src\Reservations\Reservations\Domain\Events\ReservationRescheduled;

final class LiveUpdatesServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AgendaCollaborators::class => CollaboratorsAgendaCollaborators::class,
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

        RateLimiter::for('live-authorizations', fn (Request $request): Limit => Limit::perMinute(60)->by((string) $request->user()?->getAuthIdentifier()));
    }
}
