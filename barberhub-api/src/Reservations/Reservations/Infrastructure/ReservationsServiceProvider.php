<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Reservations\Reservations\Application\Contracts\BlockedIntervalReader;
use Src\Reservations\Reservations\Application\Contracts\BookableServices;
use Src\Reservations\Reservations\Application\Contracts\CollaboratorLock;
use Src\Reservations\Reservations\Application\Contracts\CustomerMemberships;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Infrastructure\Availability\AvailabilityFreeSlots;
use Src\Reservations\Reservations\Infrastructure\Collaborators\CollaboratorsCollaboratorLock;
use Src\Reservations\Reservations\Infrastructure\Memberships\MembershipsCustomerMemberships;
use Src\Reservations\Reservations\Infrastructure\Persistence\Queries\EloquentBlockedIntervalReader;
use Src\Reservations\Reservations\Infrastructure\Persistence\Repositories\EloquentReservationRepository;
use Src\Reservations\Reservations\Infrastructure\Services\ServicesBookableServices;

final class ReservationsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ReservationRepository::class => EloquentReservationRepository::class,
        BlockedIntervalReader::class => EloquentBlockedIntervalReader::class,
        BookableServices::class => ServicesBookableServices::class,
        CollaboratorLock::class => CollaboratorsCollaboratorLock::class,
        CustomerMemberships::class => MembershipsCustomerMemberships::class,
        FreeSlots::class => AvailabilityFreeSlots::class,
    ];
}
