<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Application\Contracts\BarbershopPaymentProvider;
use Src\Payments\Payments\Application\Contracts\PayableReservations;
use Src\Payments\Payments\Application\Contracts\PayerAccounts;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\Contracts\ReservationConfirmation;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Infrastructure\Accounts\UsersPayerAccounts;
use Src\Payments\Payments\Infrastructure\Barbershops\BarbershopsBarbershopChargePolicy;
use Src\Payments\Payments\Infrastructure\Barbershops\BarbershopsBarbershopPaymentProvider;
use Src\Payments\Payments\Infrastructure\Gateways\ConfiguredPaymentGatewayRegistry;
use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;
use Src\Payments\Payments\Infrastructure\Persistence\Repositories\EloquentPaymentRepository;
use Src\Payments\Payments\Infrastructure\Reservations\ReservationsPayableReservations;
use Src\Payments\Payments\Infrastructure\Reservations\ReservationsReservationConfirmation;

final class PaymentsServiceProvider extends ServiceProvider
{
    /**
     * Ports of the module bound to their adapters.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        PaymentRepository::class => EloquentPaymentRepository::class,
        BarbershopPaymentProvider::class => BarbershopsBarbershopPaymentProvider::class,
        BarbershopChargePolicy::class => BarbershopsBarbershopChargePolicy::class,
        PaymentGatewayRegistry::class => ConfiguredPaymentGatewayRegistry::class,
        PayableReservations::class => ReservationsPayableReservations::class,
        ReservationConfirmation::class => ReservationsReservationConfirmation::class,
        PayerAccounts::class => UsersPayerAccounts::class,
    ];

    /** Bind the ONVO Pay adapter with its configuration and the resolver with the default provider of config/payments.php (PAYMENTS_DEFAULT_GATEWAY). */
    public function register(): void
    {
        $this->app->bind(OnvoGateway::class, fn (Application $app): OnvoGateway => new OnvoGateway(
            $app->make(Factory::class),
            (string) config('payments.onvo.secret_key'),
            (string) config('payments.onvo.base_url'),
            (string) config('payments.onvo.return_url'),
            (string) config('payments.onvo.sinpe_destination'),
        ));

        $this->app->bind(PaymentGatewayResolver::class, fn (Application $app): PaymentGatewayResolver => new PaymentGatewayResolver(
            $app->make(BarbershopPaymentProvider::class),
            $app->make(PaymentGatewayRegistry::class),
            PaymentProvider::from((string) config('payments.default_gateway')),
        ));
    }
}
