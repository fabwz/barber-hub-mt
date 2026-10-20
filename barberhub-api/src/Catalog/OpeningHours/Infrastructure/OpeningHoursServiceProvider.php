<?php

declare(strict_types=1);

namespace Src\Catalog\OpeningHours\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Catalog\OpeningHours\Application\Contracts\ClosedDates;
use Src\Catalog\OpeningHours\Domain\Contracts\TimeSlotRepository;
use Src\Catalog\OpeningHours\Infrastructure\Closures\ClosuresClosedDates;
use Src\Catalog\OpeningHours\Infrastructure\Persistence\Repositories\EloquentTimeSlotRepository;

final class OpeningHoursServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        TimeSlotRepository::class => EloquentTimeSlotRepository::class,
        ClosedDates::class => ClosuresClosedDates::class,
    ];
}
