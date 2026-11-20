<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\CustomerBookings;
use Src\Waitlist\Offers\Application\Contracts\OfferDeadlines;
use Src\Waitlist\Offers\Application\Contracts\OfferedSpaceReader;
use Src\Waitlist\Offers\Application\Contracts\ServiceMinutes;
use Src\Waitlist\Offers\Application\Contracts\SpaceAvailability;
use Src\Waitlist\Offers\Application\Contracts\WaitingLine;
use Src\Waitlist\Offers\Application\Contracts\WaitlistSwitch;
use Src\Waitlist\Offers\Domain\Contracts\OfferRepository;
use Src\Waitlist\Offers\Infrastructure\Availability\AvailabilitySpaceAvailability;
use Src\Waitlist\Offers\Infrastructure\Barbershops\BarbershopsWaitlistSwitch;
use Src\Waitlist\Offers\Infrastructure\Catalog\CatalogServiceMinutes;
use Src\Waitlist\Offers\Infrastructure\Collaborators\CollaboratorsCollaboratorLock;
use Src\Waitlist\Offers\Infrastructure\Entries\EntriesWaitingLine;
use Src\Waitlist\Offers\Infrastructure\Listeners\OfferSpaceReleased;
use Src\Waitlist\Offers\Infrastructure\Persistence\Queries\EloquentOfferedSpaceReader;
use Src\Waitlist\Offers\Infrastructure\Persistence\Repositories\EloquentOfferRepository;
use Src\Waitlist\Offers\Infrastructure\Queue\QueuedOfferDeadlines;
use Src\Waitlist\Offers\Infrastructure\Reservations\ReservationsCustomerBookings;

final class WaitlistOffersServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        OfferRepository::class => EloquentOfferRepository::class,
        OfferedSpaceReader::class => EloquentOfferedSpaceReader::class,
        WaitingLine::class => EntriesWaitingLine::class,
        ServiceMinutes::class => CatalogServiceMinutes::class,
        CollaboratorLock::class => CollaboratorsCollaboratorLock::class,
        SpaceAvailability::class => AvailabilitySpaceAvailability::class,
        CustomerBookings::class => ReservationsCustomerBookings::class,
        OfferDeadlines::class => QueuedOfferDeadlines::class,
        WaitlistSwitch::class => BarbershopsWaitlistSwitch::class,
    ];

    /** Listen to the spaces that reservations free: the line offers them to the first compatible person (RF-31). */
    public function boot(): void
    {
        Event::listen(SpaceReleased::class, OfferSpaceReleased::class);
    }
}
