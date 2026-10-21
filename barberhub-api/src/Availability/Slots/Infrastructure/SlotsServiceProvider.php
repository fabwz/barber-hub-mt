<?php

declare(strict_types=1);

namespace Src\Availability\Slots\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Availability\Slots\Application\Contracts\BarbershopHours;
use Src\Availability\Slots\Application\Contracts\BlockedIntervals;
use Src\Availability\Slots\Application\Contracts\ServiceCatalog;
use Src\Availability\Slots\Application\Contracts\StaffRoster;
use Src\Availability\Slots\Infrastructure\Collaborators\CollaboratorsStaffRoster;
use Src\Availability\Slots\Infrastructure\OpeningHours\OpeningHoursBarbershopHours;
use Src\Availability\Slots\Infrastructure\Reservations\ReservationsBlockedIntervals;
use Src\Availability\Slots\Infrastructure\Services\ServicesServiceCatalog;

final class SlotsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ServiceCatalog::class => ServicesServiceCatalog::class,
        BarbershopHours::class => OpeningHoursBarbershopHours::class,
        StaffRoster::class => CollaboratorsStaffRoster::class,
        BlockedIntervals::class => ReservationsBlockedIntervals::class,
    ];
}
