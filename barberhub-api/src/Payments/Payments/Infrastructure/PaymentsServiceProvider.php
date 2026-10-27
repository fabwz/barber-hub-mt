<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Src\Payments\Payments\Application\Contracts\BarbershopPaymentProvider;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Contracts\PaymentRepository;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Infrastructure\Barbershops\BarbershopsBarbershopPaymentProvider;
use Src\Payments\Payments\Infrastructure\Gateways\ConfiguredPaymentGatewayRegistry;
use Src\Payments\Payments\Infrastructure\Persistence\Repositories\EloquentPaymentRepository;

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
        PaymentGatewayRegistry::class => ConfiguredPaymentGatewayRegistry::class,
    ];

    /** Bind the resolver with the default provider of config/payments.php (PAYMENTS_DEFAULT_GATEWAY). */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayResolver::class, fn (Application $app): PaymentGatewayResolver => new PaymentGatewayResolver(
            $app->make(BarbershopPaymentProvider::class),
            $app->make(PaymentGatewayRegistry::class),
            PaymentProvider::from((string) config('payments.default_gateway')),
        ));
    }
}
