<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Infrastructure;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Src\Reservations\Reservations\Domain\Events\ReservationConfirmed;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Waitlist\Offers\Application\Contracts\CollaboratorLock;
use Src\Waitlist\Offers\Application\Contracts\CustomerBookings;
use Src\Waitlist\Offers\Application\Contracts\CustomerMemberships;
use Src\Waitlist\Offers\Application\Contracts\LineEntries;
use Src\Waitlist\Offers\Application\Contracts\OfferDeadlines;
use Src\Waitlist\Offers\Application\Contracts\OfferedBookings;
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
use Src\Waitlist\Offers\Infrastructure\Entries\EntriesLineEntries;
use Src\Waitlist\Offers\Infrastructure\Entries\EntriesWaitingLine;
use Src\Waitlist\Offers\Infrastructure\Listeners\OfferSpaceReleased;
use Src\Waitlist\Offers\Infrastructure\Listeners\SettleOfferOnReservationConfirmed;
use Src\Waitlist\Offers\Infrastructure\Memberships\MembershipsCustomerMemberships;
use Src\Waitlist\Offers\Infrastructure\Persistence\Queries\EloquentOfferedSpaceReader;
use Src\Waitlist\Offers\Infrastructure\Persistence\Repositories\EloquentOfferRepository;
use Src\Waitlist\Offers\Infrastructure\Queue\QueuedOfferDeadlines;
use Src\Waitlist\Offers\Infrastructure\Reservations\ReservationsCustomerBookings;
use Src\Waitlist\Offers\Infrastructure\Reservations\ReservationsOfferedBookings;

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
        LineEntries::class => EntriesLineEntries::class,
        CustomerMemberships::class => MembershipsCustomerMemberships::class,
        OfferedBookings::class => ReservationsOfferedBookings::class,
    ];

    /**
     * Listen to the spaces that reservations free, which the line offers to the first compatible person (RF-31), and to the reservations that
     * become confirmed, which accept the offer they were for and take the person out of the lines of that service and day (RF-32, LE-06).
     */
    public function boot(): void
    {
        Event::listen(SpaceReleased::class, OfferSpaceReleased::class);
        Event::listen(ReservationConfirmed::class, SettleOfferOnReservationConfirmed::class);
    }
}
