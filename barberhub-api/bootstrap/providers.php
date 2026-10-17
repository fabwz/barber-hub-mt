<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Src\Availability\Slots\Infrastructure\SlotsServiceProvider;
use Src\Catalog\Barbershops\Infrastructure\BarbershopsServiceProvider;
use Src\Catalog\Collaborators\Infrastructure\CollaboratorsServiceProvider;
use Src\Catalog\OpeningHours\Infrastructure\OpeningHoursServiceProvider;
use Src\Catalog\Services\Infrastructure\ServicesServiceProvider;
use Src\Identity\Memberships\Infrastructure\MembershipsServiceProvider;
use Src\Identity\Users\Infrastructure\UsersServiceProvider;
use Src\Reservations\Reservations\Infrastructure\ReservationsServiceProvider;

return [
    AppServiceProvider::class,
    UsersServiceProvider::class,
    MembershipsServiceProvider::class,
    BarbershopsServiceProvider::class,
    ServicesServiceProvider::class,
    OpeningHoursServiceProvider::class,
    CollaboratorsServiceProvider::class,
    SlotsServiceProvider::class,
    ReservationsServiceProvider::class,
];
