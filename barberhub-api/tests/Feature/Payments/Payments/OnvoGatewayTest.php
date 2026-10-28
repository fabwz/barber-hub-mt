<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\NextActionType;
use Src\Payments\Payments\Application\DTOs\PaymentDetails;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Percentage;
use Tests\TestCase;

/** The ONVO Pay adapter against the answers of the provider, simulated: no request leaves the process and no money moves (PA-09). */
final class OnvoGatewayTest extends TestCase
{
    private const string BASE = 'https://api.onvopay.com/v1';

    private const string TEST_KEY = 'onvo_test_secret_key_abc';

    private const string PAYMENT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f01';

    private const string RESERVATION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f02';

    /** No test of payments may reach the real provider. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** ₡5.000 is sent as 500000, in CRC, with the secret key as a bearer token and the references of the payment as metadata. */
    public function test_the_amount_goes_in_the_minimum_unit(): void
    {
        $this->fakeApproval();

        $result = $this->gateway()->charge($this->cardCharge(5000));

        $this->assertSame(PaymentStatus::Approved, $result->status);
        $this->assertSame('pi_1', $result->providerPaymentId);
        $this->assertSame(500000, $result->providerAmount->minorUnits);
        $this->assertSame('CRC', $result->providerAmount->currency);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/payment-intents'
            && $request['amount'] === 500000
            && $request['currency'] === 'CRC'
            && $request['metadata'] === ['paymentId' => self::PAYMENT, 'reservationId' => self::RESERVATION]
            && $request->hasHeader('Authorization', 'Bearer '.self::TEST_KEY));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/payment-intents/pi_1/confirm' && $request['paymentMethodId'] === 'pm_1');
    }

    /** A 30 % deposit of ₡7.000 is ₡2.100 and goes as 210000; the card number never appears in what is sent. */
    public function test_the_deposit_is_rounded_up_and_sent_in_the_minimum_unit(): void
    {
        $this->fakeApproval();
        $deposit = new PaymentAmountCalculator()->deposit(Money::of(7000), Percentage::of(30));

        $this->gateway()->charge($this->cardCharge($deposit->colones));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/payment-intents' && $request['amount'] === 210000);
        Http::assertNotSent(fn (Request $request): bool => isset($request['card']) || isset($request['number']));
    }

    /** A deposit with cents to round: ₡8.500 at 30 % is ₡2.550, sent as 255000. */
    public function test_the_deposit_of_the_issue_is_sent_as_255000(): void
    {
        $this->fakeApproval();

        $this->gateway()->charge($this->cardCharge(2550));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/payment-intents' && $request['amount'] === 255000);
    }

    /** A card that ONVO Pay declines is a refusal with its reason, not an error. */
    public function test_a_declined_card_is_rejected_with_the_reason(): void
    {
        Http::fake([
            self::BASE.'/payment-intents' => Http::response(['id' => 'pi_2', 'status' => 'requires_payment_method']),
            self::BASE.'/payment-intents/pi_2/confirm' => Http::response(['id' => 'pi_2', 'status' => 'requires_payment_method', 'lastPaymentError' => ['code' => 'declined', 'message' => 'Fondos insuficientes']]),
        ]);

        $result = $this->gateway()->charge($this->cardCharge(5000));

        $this->assertSame(PaymentStatus::Rejected, $result->status);
        $this->assertSame('Fondos insuficientes', $result->failureReason);
        $this->assertSame('pi_2', $result->providerPaymentId);
    }

    /** A 3DS check leaves the charge pending with a redirect. */
    public function test_a_card_that_requires_action_is_pending_with_a_redirect(): void
    {
        Http::fake([
            self::BASE.'/payment-intents' => Http::response(['id' => 'pi_3', 'status' => 'requires_payment_method']),
            self::BASE.'/payment-intents/pi_3/confirm' => Http::response(['id' => 'pi_3', 'status' => 'requires_action', 'nextAction' => ['type' => 'redirect_to_url', 'redirectToUrl' => ['url' => 'https://secure.test/3ds']]]),
        ]);

        $result = $this->gateway()->charge($this->cardCharge(5000));

        $this->assertSame(PaymentStatus::Pending, $result->status);
        $this->assertSame(NextActionType::Redirect, $result->nextAction?->type);
        $this->assertSame('https://secure.test/3ds', $result->nextAction->value);
    }

    /** Before the intent exists, a timeout, a lost connection or a 5xx is a communication error with no charge, and nothing is retried. */
    public function test_a_communication_error_before_the_charge_carries_no_charge(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        try {
            $this->gateway()->charge($this->cardCharge(5000));
            $this->fail('The timeout was not reported.');
        } catch (GatewayUnavailableException $exception) {
            $this->assertNull($exception->created);
        }

        Http::fake([self::BASE.'/payment-intents' => Http::response(['message' => 'down'], 503)]);

        try {
            $this->gateway()->charge($this->cardCharge(5000));
            $this->fail('The 5xx was not reported.');
        } catch (GatewayUnavailableException $exception) {
            $this->assertNull($exception->created);
        }
    }

    /** After the intent exists, a failure while confirming carries the intent, so the payment waits pending for the status query. */
    public function test_a_communication_error_after_the_charge_carries_the_charge(): void
    {
        Http::fake([
            self::BASE.'/payment-intents' => Http::response(['id' => 'pi_4', 'status' => 'requires_payment_method']),
            self::BASE.'/payment-intents/pi_4/confirm' => Http::response(['message' => 'down'], 500),
        ]);

        try {
            $this->gateway()->charge($this->cardCharge(5000));
            $this->fail('The 5xx was not reported.');
        } catch (GatewayUnavailableException $exception) {
            $this->assertSame('pi_4', $exception->created?->providerPaymentId);
            $this->assertSame(PaymentStatus::Pending, $exception->created->status);
            $this->assertSame(500000, $exception->created->providerAmount->minorUnits);
        }
        Http::assertSentCount(2);
    }

    /** SINPE Móvil creates a `mobile_number` method, confirms the intent, stays pending with the destination and the exact amount, and is approved by a later query. */
    public function test_sinpe_movil_is_pending_with_instructions_and_then_approved(): void
    {
        Http::fake([
            self::BASE.'/payment-methods' => Http::response(['id' => 'pm_sinpe']),
            self::BASE.'/payment-intents' => Http::response(['id' => 'pi_5', 'status' => 'requires_payment_method']),
            self::BASE.'/payment-intents/pi_5/confirm' => Http::response(['id' => 'pi_5', 'status' => 'processing']),
            self::BASE.'/payment-intents/pi_5' => Http::sequence()->push(['id' => 'pi_5', 'status' => 'processing'])->push(['id' => 'pi_5', 'status' => 'succeeded']),
        ]);
        $gateway = $this->gateway();

        $result = $gateway->charge(new ChargeRequest(
            new PaymentId(self::PAYMENT),
            new ReservationId(self::RESERVATION),
            Money::of(2100),
            PaymentMethod::SinpeMovil,
            PaymentDetails::sinpeMovil('+50688888888', '01-1393-1919', '0', 'Ana Rojas', 'ana@barberhub.test'),
        ));

        $this->assertSame(PaymentStatus::Pending, $result->status);
        $this->assertSame(NextActionType::Instructions, $result->nextAction?->type);
        $this->assertSame('+506 70196686', $result->nextAction->value);
        $this->assertSame(2100, $result->nextAction->amount?->colones);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/payment-methods'
            && $request['type'] === 'mobile_number'
            && $request['mobileNumber'] === ['identification' => '01-1393-1919', 'identificationType' => 0, 'number' => '+50688888888']
            && $request['billing'] === ['name' => 'Ana Rojas', 'email' => 'ana@barberhub.test']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/payment-intents' && $request['amount'] === 210000);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/payment-intents/pi_5/confirm' && $request['paymentMethodId'] === 'pm_sinpe');

        $this->assertSame(PaymentStatus::Pending, $gateway->status('pi_5', new PaymentId(self::PAYMENT))->status);
        $this->assertSame(PaymentStatus::Approved, $gateway->status('pi_5', new PaymentId(self::PAYMENT))->status);
    }

    /** If the SINPE Móvil method cannot be created there is no charge at all, so the payment can fail and be retried. */
    public function test_a_failure_creating_the_sinpe_movil_method_leaves_no_charge(): void
    {
        Http::fake([self::BASE.'/payment-methods' => Http::response(['message' => 'down'], 502)]);

        try {
            $this->gateway()->charge(new ChargeRequest(new PaymentId(self::PAYMENT), new ReservationId(self::RESERVATION), Money::of(2100), PaymentMethod::SinpeMovil, PaymentDetails::sinpeMovil('+50688888888', '01-1393-1919', '0', 'Ana Rojas', 'ana@barberhub.test')));
            $this->fail('The 5xx was not reported.');
        } catch (GatewayUnavailableException $exception) {
            $this->assertNull($exception->created);
        }
        Http::assertSentCount(1);
    }

    /** The status query translates the states of ONVO Pay. */
    public function test_the_status_query_translates_the_states(): void
    {
        $gateway = $this->gateway();

        Http::fake([self::BASE.'/payment-intents/pi_6' => Http::sequence()
            ->push(['status' => 'succeeded'])
            ->push(['status' => 'requires_payment_method', 'lastPaymentError' => ['message' => 'Tarjeta vencida']])
            ->push(['status' => 'requires_payment_method'])
            ->push(['status' => 'canceled'])
            ->push(['status' => 'processing'])
            ->push(['message' => 'down'], 500)]);

        $this->assertSame(PaymentStatus::Approved, $gateway->status('pi_6', new PaymentId(self::PAYMENT))->status);
        $rejected = $gateway->status('pi_6', new PaymentId(self::PAYMENT));
        $this->assertSame(PaymentStatus::Rejected, $rejected->status);
        $this->assertSame('Tarjeta vencida', $rejected->failureReason);
        $this->assertSame(PaymentStatus::Pending, $gateway->status('pi_6', new PaymentId(self::PAYMENT))->status);
        $this->assertSame(PaymentStatus::Failed, $gateway->status('pi_6', new PaymentId(self::PAYMENT))->status);
        $this->assertSame(PaymentStatus::Pending, $gateway->status('pi_6', new PaymentId(self::PAYMENT))->status);

        $this->expectException(GatewayUnavailableException::class);
        $gateway->status('pi_6', new PaymentId(self::PAYMENT));
    }

    /** A refund goes to /refunds with the intent and the whole amount in the minimum unit; a refusal and a failure are told apart. */
    public function test_a_refund_is_sent_in_the_minimum_unit_and_a_refusal_is_a_result(): void
    {
        Http::fake([self::BASE.'/refunds' => Http::sequence()
            ->push(['id' => 're_1', 'status' => 'succeeded'])
            ->push(['message' => 'Already refunded'], 400)
            ->push(['message' => 'down'], 500)]);
        $gateway = $this->gateway();

        $accepted = $gateway->refund('pi_1', Money::of(5000), new PaymentId(self::PAYMENT));
        $this->assertTrue($accepted->accepted);
        $this->assertSame('re_1', $accepted->refundId);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/refunds' && $request['paymentIntentId'] === 'pi_1' && $request['amount'] === 500000);

        $refused = $gateway->refund('pi_1', Money::of(5000), new PaymentId(self::PAYMENT));
        $this->assertFalse($refused->accepted);
        $this->assertSame('Already refunded', $refused->failureReason);

        $this->expectException(GatewayUnavailableException::class);
        $gateway->refund('pi_1', Money::of(5000), new PaymentId(self::PAYMENT));
    }

    /** A live key is refused when the adapter is built, whatever else is configured (PA-09). */
    public function test_a_live_key_is_never_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OnvoGateway(new Factory, 'onvo_live_secret_key_abc', self::BASE, '', '+506 70196686');
    }

    /** The container builds the adapter from config/payments.php and refuses a live key there too. */
    public function test_the_container_builds_the_adapter_from_the_configuration(): void
    {
        config(['payments.onvo.secret_key' => self::TEST_KEY]);
        $this->assertSame(PaymentProvider::Onvo, $this->app->make(OnvoGateway::class)->provider());

        config(['payments.onvo.secret_key' => 'onvo_live_secret_key_abc']);
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(OnvoGateway::class);
    }

    /** The timeouts are 10 s overall and 5 s to connect. */
    public function test_the_timeouts_are_defined(): void
    {
        $this->assertSame(10, OnvoGateway::TIMEOUT_SECONDS);
        $this->assertSame(5, OnvoGateway::CONNECT_TIMEOUT_SECONDS);
    }

    /** ONVO Pay admits the card and SINPE Móvil. */
    public function test_it_supports_the_card_and_sinpe_movil(): void
    {
        $this->assertTrue($this->gateway()->supports(PaymentMethod::Card));
        $this->assertTrue($this->gateway()->supports(PaymentMethod::SinpeMovil));
    }

    /** The adapter with a test key, as the container would build it. */
    private function gateway(): OnvoGateway
    {
        return new OnvoGateway($this->app->make(Factory::class), self::TEST_KEY, self::BASE, '', '+506 70196686');
    }

    /** A card charge of the amount, with the identifier of a payment method created in the browser. */
    private function cardCharge(int $colones): ChargeRequest
    {
        return new ChargeRequest(new PaymentId(self::PAYMENT), new ReservationId(self::RESERVATION), Money::of($colones), PaymentMethod::Card, PaymentDetails::card('pm_1'));
    }

    /** ONVO Pay creates the intent `pi_1` and approves it when it is confirmed. */
    private function fakeApproval(): void
    {
        Http::fake([
            self::BASE.'/payment-intents' => Http::response(['id' => 'pi_1', 'status' => 'requires_payment_method']),
            self::BASE.'/payment-intents/pi_1/confirm' => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
        ]);
    }
}
