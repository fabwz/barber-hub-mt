<?php

declare(strict_types=1);

namespace Tests\Unit\Payments\Payments\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\NextActionType;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\PaymentGatewayNotConfiguredException;
use Src\Payments\Payments\Application\PaymentGatewayResolver;
use Src\Payments\Payments\Domain\Entities\Payment;
use Src\Payments\Payments\Domain\Exceptions\PaymentMethodNotSupportedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\Money;
use Tests\Support\Payments\ArrayPaymentGatewayRegistry;
use Tests\Support\Payments\ChosenPaymentProvider;
use Tests\Support\Payments\SimulatedPaymentGateway;

final class PaymentGatewayResolverTest extends TestCase
{
    private SimulatedPaymentGateway $onvo;

    private SimulatedPaymentGateway $paypal;

    private ChosenPaymentProvider $chosen;

    /** ONVO Pay admits the card and SINPE Móvil; PayPal admits the card (PA-02). */
    protected function setUp(): void
    {
        parent::setUp();

        $this->onvo = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card, PaymentMethod::SinpeMovil]);
        $this->paypal = new SimulatedPaymentGateway(PaymentProvider::Paypal, [PaymentMethod::Card]);
        $this->chosen = new ChosenPaymentProvider;
    }

    /** A new charge goes through the gateway the barbershop chose, whichever it is. */
    public function test_a_new_charge_uses_the_gateway_of_the_barbershop(): void
    {
        $resolver = $this->resolver(PaymentProvider::Onvo);

        $this->chosen->provider = PaymentProvider::Paypal;
        $this->assertSame($this->paypal, $resolver->forNewCharge(PaymentMethod::Card));

        $this->chosen->provider = PaymentProvider::Onvo;
        $this->assertSame($this->onvo, $resolver->forNewCharge(PaymentMethod::Card));
    }

    /** A barbershop with no gateway chosen falls back to the default of the system. */
    public function test_a_barbershop_without_gateway_uses_the_default(): void
    {
        $this->assertSame($this->onvo, $this->resolver(PaymentProvider::Onvo)->forNewCharge(PaymentMethod::Card));
        $this->assertSame($this->paypal, $this->resolver(PaymentProvider::Paypal)->forNewCharge(PaymentMethod::Card));
    }

    /** A payment already created keeps its provider when the owner changes the gateway of the barbershop (RF-23). */
    public function test_a_payment_in_course_keeps_its_provider(): void
    {
        $this->chosen->provider = PaymentProvider::Onvo;
        $resolver = $this->resolver(PaymentProvider::Onvo);
        $payment = $this->paymentWith(PaymentProvider::Onvo);

        $this->chosen->provider = PaymentProvider::Paypal;

        $this->assertSame($this->onvo, $resolver->forPayment($payment));
        $this->assertSame($this->paypal, $resolver->forNewCharge(PaymentMethod::Card));
        $this->assertSame($this->paypal, $resolver->forPayment($this->paymentWith(PaymentProvider::Paypal)));
    }

    /** SINPE Móvil is admitted by ONVO Pay and refused, with a clear error on the field `metodo`, by PayPal. */
    public function test_a_method_the_gateway_does_not_admit_is_refused(): void
    {
        $this->chosen->provider = PaymentProvider::Onvo;
        $this->assertSame($this->onvo, $this->resolver(PaymentProvider::Onvo)->forNewCharge(PaymentMethod::SinpeMovil));

        $this->chosen->provider = PaymentProvider::Paypal;

        try {
            $this->resolver(PaymentProvider::Onvo)->forNewCharge(PaymentMethod::SinpeMovil);
            $this->fail('PayPal admitted SINPE Móvil.');
        } catch (PaymentMethodNotSupportedException $exception) {
            $this->assertSame('metodo', $exception->field());
            $this->assertSame('payments.method_not_supported', $exception->translationKey());
            $this->assertSame(['method' => 'sinpe_movil', 'provider' => 'paypal'], $exception->parameters());
        }
    }

    /** The unsupported method of the default gateway is refused too, not only the one of the barbershop. */
    public function test_the_default_gateway_is_checked_as_well(): void
    {
        $this->expectException(PaymentMethodNotSupportedException::class);

        $this->resolver(PaymentProvider::Paypal)->forNewCharge(PaymentMethod::SinpeMovil);
    }

    /** A provider with no adapter is a configuration error, not a refusal of the customer's method. */
    public function test_a_provider_without_adapter_is_a_configuration_error(): void
    {
        $resolver = new PaymentGatewayResolver($this->chosen, new ArrayPaymentGatewayRegistry(['onvo' => $this->onvo]), PaymentProvider::Onvo);
        $this->chosen->provider = PaymentProvider::Paypal;

        $this->expectException(PaymentGatewayNotConfiguredException::class);

        $resolver->forNewCharge(PaymentMethod::Card);
    }

    /** The simulated gateway approves, rejects or fails by communication as the test says, always in colones and without moving money. */
    public function test_the_simulated_gateway_can_approve_reject_or_fail(): void
    {
        $request = new ChargeRequest(new PaymentId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f01'), new ReservationId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f02'), Money::of(2550), PaymentMethod::Card);

        $approved = $this->onvo->approves()->charge($request);
        $this->assertSame(PaymentStatus::Approved, $approved->status);
        $this->assertSame('USD', $approved->providerAmount->currency);
        $this->assertSame(510, $approved->providerAmount->minorUnits);
        $this->assertNull($approved->nextAction);
        $this->assertSame(PaymentStatus::Approved, $this->onvo->status($approved->providerPaymentId, $request->paymentId)->status);

        $pending = $this->onvo->leavesPending()->charge($request);
        $this->assertSame(PaymentStatus::Pending, $pending->status);
        $this->assertSame(NextActionType::Redirect, $pending->nextAction?->type);

        $rejected = $this->onvo->rejects('Card declined')->charge($request);
        $this->assertSame(PaymentStatus::Rejected, $rejected->status);
        $this->assertSame('Card declined', $rejected->failureReason);

        $this->assertTrue($this->onvo->refund($approved->providerPaymentId, Money::of(2550), $request->paymentId)->accepted);

        $this->onvo->isUnavailable();
        $this->expectException(GatewayUnavailableException::class);
        $this->onvo->charge($request);
    }

    private function resolver(PaymentProvider $default): PaymentGatewayResolver
    {
        return new PaymentGatewayResolver($this->chosen, new ArrayPaymentGatewayRegistry(['onvo' => $this->onvo, 'paypal' => $this->paypal]), $default);
    }

    private function paymentWith(PaymentProvider $provider): Payment
    {
        return Payment::create(
            new PaymentId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f03'),
            new BarbershopId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f04'),
            new ReservationId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f02'),
            $provider,
            PaymentMethod::Card,
            Money::of(2550),
            new DateTimeImmutable('2030-03-01 08:00:00-06:00'),
        );
    }
}
