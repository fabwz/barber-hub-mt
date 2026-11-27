<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Src\Availability\Slots\Infrastructure\SlotsServiceProvider;
use Src\Catalog\Barbershops\Infrastructure\BarbershopsServiceProvider;
use Src\Catalog\Closures\Infrastructure\ClosuresServiceProvider;
use Src\Catalog\Collaborators\Infrastructure\CollaboratorsServiceProvider;
use Src\Catalog\Invitations\Infrastructure\InvitationsServiceProvider;
use Src\Catalog\OpeningHours\Infrastructure\OpeningHoursServiceProvider;
use Src\Catalog\Services\Infrastructure\ServicesServiceProvider;
use Src\Identity\Memberships\Infrastructure\MembershipsServiceProvider;
use Src\Identity\Users\Infrastructure\UsersServiceProvider;
use Src\Notifications\LiveUpdates\Infrastructure\LiveUpdatesServiceProvider;
use Src\Notifications\Notices\Infrastructure\NoticesServiceProvider;
use Src\Payments\Income\Infrastructure\IncomeServiceProvider;
use Src\Payments\Payments\Infrastructure\PaymentsServiceProvider;
use Src\Reservations\Reservations\Infrastructure\ReservationsServiceProvider;
use Src\Shared\Infrastructure\SharedServiceProvider;
use Src\Shared\Monitoring\Infrastructure\MonitoringServiceProvider;
use Src\Vouchers\PurchasedVouchers\Infrastructure\PurchasedVouchersServiceProvider;
use Src\Vouchers\VoucherDefinitions\Infrastructure\VoucherDefinitionsServiceProvider;
use Src\Waitlist\Entries\Infrastructure\WaitlistServiceProvider;
use Src\Waitlist\Offers\Infrastructure\WaitlistOffersServiceProvider;

return [
    AppServiceProvider::class,
    SharedServiceProvider::class,
    MonitoringServiceProvider::class,
    UsersServiceProvider::class,
    MembershipsServiceProvider::class,
    BarbershopsServiceProvider::class,
    ServicesServiceProvider::class,
    OpeningHoursServiceProvider::class,
    ClosuresServiceProvider::class,
    CollaboratorsServiceProvider::class,
    InvitationsServiceProvider::class,
    SlotsServiceProvider::class,
    ReservationsServiceProvider::class,
    PaymentsServiceProvider::class,
    IncomeServiceProvider::class,
    VoucherDefinitionsServiceProvider::class,
    PurchasedVouchersServiceProvider::class,
    WaitlistServiceProvider::class,
    WaitlistOffersServiceProvider::class,
    NoticesServiceProvider::class,
    LiveUpdatesServiceProvider::class,
];
