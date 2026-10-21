<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Src\Reservations\Reservations\Domain\Contracts\ReservationRepository;
use Src\Reservations\Reservations\Infrastructure\Persistence\Repositories\EloquentReservationRepository;

final class ReservationsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ReservationRepository::class => EloquentReservationRepository::class,
    ];
}
