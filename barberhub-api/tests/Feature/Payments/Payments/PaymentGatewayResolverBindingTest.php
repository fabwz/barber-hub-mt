<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Payments\Payments\Application\Contracts\BarbershopPaymentProvider;
use Src\Payments\Payments\Application\Contracts\PaymentGatewayRegistry;
use Src\Payments\Payments\Application\Exceptions\PaymentGatewayNotConfiguredException;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Exceptions\PaymentMethodNotSupportedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Infrastructure\Http\Problem\ProblemDetailsRenderer;
use Tests\Support\Payments\SimulatedPaymentGateway;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\ScopesQueriesToBarbershop;
use Tests\TestCase;

/** The resolver is bound by the container: it reads the gateway of the barbershop, falls back to the configured default and knows the adapters from the configuration. */
final class PaymentGatewayResolverBindingTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use ScopesQueriesToBarbershop;

    private SimulatedPaymentGateway $onvo;

    private SimulatedPaymentGateway $paypal;

    /** Register the simulated gateways as the adapters of both providers. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->onvo = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card, PaymentMethod::SinpeMovil]);
        $this->paypal = new SimulatedPaymentGateway(PaymentProvider::Paypal, [PaymentMethod::Card]);
        $this->app->instance('tests.gateway.onvo', $this->onvo);
        $this->app->instance('tests.gateway.paypal', $this->paypal);
        config(['payments.gateways' => ['onvo' => 'tests.gateway.onvo', 'paypal' => 'tests.gateway.paypal'], 'payments.default_gateway' => 'onvo']);
    }

    /** The container resolves the ports of the module to their adapters. */
    public function test_the_container_binds_the_ports(): void
    {
        $this->assertInstanceOf(PaymentGatewayResolver::class, $this->app->make(PaymentGatewayResolver::class));
        $this->assertSame($this->paypal, $this->app->make(PaymentGatewayRegistry::class)->for(PaymentProvider::Paypal));
    }

    /** The barbershop that chose PayPal charges with PayPal; the one that chose ONVO Pay, with ONVO Pay. */
    public function test_the_gateway_of_the_barbershop_is_used(): void
    {
        $this->actAs($this->insertBarbershop('pura-navaja', 'paypal'));
        $this->assertSame($this->paypal, $this->app->make(PaymentGatewayResolver::class)->forNewCharge(PaymentMethod::Card));

        $this->actAs($this->insertBarbershop('fade-lab', 'onvo'));
        $this->assertSame($this->onvo, $this->app->make(PaymentGatewayResolver::class)->forNewCharge(PaymentMethod::Card));
    }

    /** A barbershop without a gateway uses PAYMENTS_DEFAULT_GATEWAY, as set in config/payments.php. */
    public function test_a_barbershop_without_gateway_uses_the_configured_default(): void
    {
        $this->actAs($this->insertBarbershop('sin-pasarela', null));

        $this->assertSame($this->onvo, $this->app->make(PaymentGatewayResolver::class)->forNewCharge(PaymentMethod::Card));

        config(['payments.default_gateway' => 'paypal']);

        $this->assertSame($this->paypal, $this->app->make(PaymentGatewayResolver::class)->forNewCharge(PaymentMethod::Card));
    }

    /** The owner changes the gateway: new charges follow it and the payment in course keeps its own provider (RF-23). */
    public function test_changing_the_gateway_does_not_touch_a_payment_in_course(): void
    {
        $barbershop = $this->insertBarbershop('pura-navaja', 'onvo');
        $this->actAs($barbershop);
        $this->assertSame($this->onvo, $this->app->make(PaymentGatewayResolver::class)->forNewCharge(PaymentMethod::Card));

        DB::table('barbershops')->where('id', $barbershop)->update(['payment_gateway' => 'paypal']);

        $this->assertSame($this->paypal, $this->app->make(PaymentGatewayResolver::class)->forNewCharge(PaymentMethod::Card));
    }

    /** SINPE Móvil with PayPal is a 422 on the field `metodo`, in the same problem document as any validation error. */
    public function test_an_unsupported_method_answers_422_on_the_field_metodo(): void
    {
        $this->actAs($this->insertBarbershop('pura-navaja', 'paypal'));

        try {
            $this->app->make(PaymentGatewayResolver::class)->forNewCharge(PaymentMethod::SinpeMovil);
            $this->fail('PayPal admitted SINPE Móvil.');
        } catch (PaymentMethodNotSupportedException $exception) {
            $response = (new ProblemDetailsRenderer)($exception);
        }

        $body = $response->getData(true);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringEndsWith('/problemas/datos-invalidos', $body['type']);
        $this->assertSame(['metodo' => ['La pasarela de la barbería no admite el medio de pago sinpe_movil.']], $body['errores']);
    }

    /** A provider with no adapter in the configuration fails as a configuration error. */
    public function test_a_provider_without_adapter_in_the_configuration_fails(): void
    {
        config(['payments.gateways' => ['onvo' => 'tests.gateway.onvo']]);

        $this->expectException(PaymentGatewayNotConfiguredException::class);

        $this->app->make(PaymentGatewayRegistry::class)->for(PaymentProvider::Paypal);
    }

    /** An adapter that does not implement the port is refused too. */
    public function test_a_class_that_is_not_a_gateway_is_refused(): void
    {
        config(['payments.gateways' => ['onvo' => BarbershopPaymentProvider::class]]);
        $this->app->bind(BarbershopPaymentProvider::class, fn () => new class {});

        $this->expectException(PaymentGatewayNotConfiguredException::class);

        $this->app->make(PaymentGatewayRegistry::class)->for(PaymentProvider::Onvo);
    }

    /** Make the barbershop the current one of the container, as the middleware does for a request. */
    private function actAs(string $barbershopId): void
    {
        $this->app->instance(BarbershopContext::class, new FixedBarbershopContext(new BarbershopId($barbershopId)));
        $this->scopeQueriesTo($barbershopId);
    }

    /** Insert a barbershop that collects through the gateway (or none) and return its identifier. */
    private function insertBarbershop(string $link, ?string $gateway): string
    {
        $id = (string) Str::uuid7();
        DB::table('barbershops')->insert([
            'id' => $id,
            'name' => $link,
            'city' => 'San Carlos',
            'phone' => '2460-0000',
            'public_link' => $link,
            'payment_gateway' => $gateway,
            'payment_mode' => $gateway === null ? 'none' : 'full',
        ]);

        return $id;
    }
}
