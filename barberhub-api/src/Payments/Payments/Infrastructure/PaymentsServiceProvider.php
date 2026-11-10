<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Src\Payments\Payments\Application\Contracts\BarbershopChargePolicy;
use Src\Payments\Payments\Application\Contracts\BarbershopPaymentProvider;
use Src\Payments\Payments\Application\Contracts\BarbershopScope;
use Src\Payments\Payments\Application\Contracts\GatewayNoticeVerifiers;
use Src\Payments\Payments\Application\Contracts\PayableReservations;
use Src\Payments\Payments\Application\Contracts\PayerAccounts;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\Contracts\PaymentLocator;
use Src\Payments\Payments\Application\Contracts\ProcessedNotices;
use Src\Payments\Payments\Application\Contracts\ReservationConfirmation;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Infrastructure\Accounts\UsersPayerAccounts;
use Src\Payments\Payments\Infrastructure\Barbershops\BarbershopsBarbershopChargePolicy;
use Src\Payments\Payments\Infrastructure\Barbershops\BarbershopsBarbershopPaymentProvider;
use Src\Payments\Payments\Infrastructure\Gateways\ConfiguredPaymentGatewayRegistry;
use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;
use Src\Payments\Payments\Infrastructure\Gateways\PayPalGateway;
use Src\Payments\Payments\Infrastructure\Notices\ConfiguredNoticeVerifiers;
use Src\Payments\Payments\Infrastructure\Notices\DatabasePaymentLocator;
use Src\Payments\Payments\Infrastructure\Notices\DatabaseProcessedNotices;
use Src\Payments\Payments\Infrastructure\Notices\OnvoNoticeVerifier;
use Src\Payments\Payments\Infrastructure\Notices\PayPalNoticeVerifier;
use Src\Payments\Payments\Infrastructure\Notices\RequestBarbershopScope;
use Src\Payments\Payments\Infrastructure\Persistence\Repositories\EloquentPaymentRepository;
use Src\Payments\Payments\Infrastructure\Reservations\ReservationsPayableReservations;
use Src\Payments\Payments\Infrastructure\Reservations\ReservationsReservationConfirmation;
use Src\Shared\Domain\Contracts\Clock;

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
        PaymentLocator::class => DatabasePaymentLocator::class,
        ProcessedNotices::class => DatabaseProcessedNotices::class,
        BarbershopScope::class => RequestBarbershopScope::class,
    ];

    /** Bind the adapters of ONVO Pay and PayPal with their configuration and the resolver with the default provider of config/payments.php (PAYMENTS_DEFAULT_GATEWAY). */
    public function register(): void
    {
        $this->app->bind(OnvoGateway::class, fn (Application $app): OnvoGateway => new OnvoGateway(
            $app->make(Factory::class),
            (string) config('payments.onvo.secret_key'),
            (string) config('payments.onvo.base_url'),
            (string) config('payments.onvo.return_url'),
            (string) config('payments.onvo.sinpe_destination'),
        ));

        $this->app->bind(PayPalGateway::class, fn (Application $app): PayPalGateway => new PayPalGateway(
            $app->make(Factory::class),
            $app->make(CacheRepository::class),
            (string) config('payments.paypal.client_id'),
            (string) config('payments.paypal.client_secret'),
            (string) config('payments.paypal.base_url'),
            (string) config('payments.paypal.return_url'),
            (string) config('payments.paypal.cancel_url'),
            (string) config('payments.paypal.crc_per_usd'),
        ));

        $this->app->bind(GatewayNoticeVerifiers::class, fn (Application $app): GatewayNoticeVerifiers => new ConfiguredNoticeVerifiers([
            PaymentProvider::Onvo->value => fn (): OnvoNoticeVerifier => new OnvoNoticeVerifier((string) config('payments.onvo.webhook_secret'), $app->make(Clock::class)),
            PaymentProvider::Paypal->value => fn (): PayPalNoticeVerifier => new PayPalNoticeVerifier($app->make(PayPalGateway::class), (string) config('payments.paypal.webhook_id')),
        ]));

        $this->app->bind(PaymentGatewayResolver::class, fn (Application $app): PaymentGatewayResolver => new PaymentGatewayResolver(
            $app->make(BarbershopPaymentProvider::class),
            $app->make(PaymentGatewayRegistry::class),
            PaymentProvider::from((string) config('payments.default_gateway')),
        ));
    }

    /** Limit the notices of the gateways per address: they are public, so nobody can flood the verification. */
    public function boot(): void
    {
        RateLimiter::for('payment-notices', fn (Request $request): Limit => Limit::perMinute(120)->by((string) $request->ip()));
    }
}
