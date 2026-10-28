<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\NextActionType;
use Src\Payments\Payments\Application\DTOs\PaymentDetails;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\RefundNotSupportedException;
use Src\Payments\Payments\Domain\Services\PaymentAmountCalculator;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Payments\Payments\Infrastructure\Gateways\PayPalGateway;
use Src\Shared\Domain\ValueObjects\Money;
use Src\Shared\Domain\ValueObjects\Percentage;
use Tests\TestCase;

/** The PayPal adapter against the answers of the provider, simulated: no request leaves the process and no money moves (PA-09). */
final class PayPalGatewayTest extends TestCase
{
    private const string BASE = 'https://api-m.sandbox.paypal.com';

    private const string PAYMENT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f01';

    private const string OTHER_PAYMENT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f09';

    private const string RESERVATION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f02';

    private const string TOKEN = 'A21AAL_test_access_token';

    /** No test of payments may reach the real provider, and no token may stay in the cache from another test. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
    }

    /** ₡2.550 at 500 colones per dollar is "5.10": a string with two decimals, in USD, with the references of the payment and the reservation. */
    public function test_the_amount_is_converted_to_dollars_and_sent_as_decimal_text(): void
    {
        $this->fakeOrder();

        $result = $this->gateway()->charge($this->cardCharge(2550));

        $this->assertSame(PaymentStatus::Pending, $result->status);
        $this->assertSame('ORDER-1', $result->providerPaymentId);
        $this->assertSame('USD', $result->providerAmount->currency);
        $this->assertSame(510, $result->providerAmount->minorUnits);
        $this->assertSame('500.0000', $result->providerAmount->exchangeRate);
        Http::assertSent(function (Request $request): bool {
            if ($request->url() !== self::BASE.'/v2/checkout/orders') {
                return false;
            }

            $unit = $request['purchase_units'][0];

            return $request->method() === 'POST'
                && $request['intent'] === 'CAPTURE'
                && $unit['amount'] === ['currency_code' => 'USD', 'value' => '5.10']
                && $unit['reference_id'] === self::PAYMENT
                && $unit['custom_id'] === self::RESERVATION
                && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN);
        });
    }

    /** A 30 % deposit of ₡7.000 is ₡2.100 and goes as "4.20". */
    public function test_a_deposit_is_converted_to_dollars(): void
    {
        $this->fakeOrder();
        $deposit = new PaymentAmountCalculator()->deposit(Money::of(7000), Percentage::of(30));

        $result = $this->gateway()->charge($this->cardCharge($deposit->colones));

        $this->assertSame(2100, $deposit->colones);
        $this->assertSame(420, $result->providerAmount->minorUnits);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/v2/checkout/orders' && $request['purchase_units'][0]['amount']['value'] === '4.20');
    }

    /** The conversion rounds to the cent and follows the configured rate. */
    public function test_the_conversion_rounds_to_the_cent_with_the_configured_rate(): void
    {
        $this->fakeOrder();

        $result = $this->gateway(colonesPerDollar: 515)->charge($this->cardCharge(2550));

        $this->assertSame(495, $result->providerAmount->minorUnits);
        $this->assertSame('515.0000', $result->providerAmount->exchangeRate);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/v2/checkout/orders' && $request['purchase_units'][0]['amount']['value'] === '4.95');
    }

    /** The customer pays as a guest with a card on the page of PayPal; the result is the address to go to, never a decision. */
    public function test_the_card_experience_is_requested_and_the_result_redirects(): void
    {
        $this->fakeOrder();

        $result = $this->gateway()->charge($this->cardCharge(2550));

        $this->assertSame(NextActionType::Redirect, $result->nextAction?->type);
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1', $result->nextAction->value);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/v2/checkout/orders'
            && $request['payment_source']['paypal']['experience_context'] === [
                'brand_name' => 'BarberHub',
                'landing_page' => 'GUEST_CHECKOUT',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => 'https://app.test/api/v1/pagos/retorno',
                'cancel_url' => 'https://app.test/cancelado',
            ]);
    }

    /** The link `approve` is used when the order has no `payer-action`. */
    public function test_the_approve_link_is_used_when_there_is_no_payer_action(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders' => Http::response(['id' => 'ORDER-2', 'status' => 'CREATED', 'links' => [['rel' => 'self', 'href' => 'https://x.test/self'], ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-2']]], 201),
        ]);

        $result = $this->gateway()->charge($this->cardCharge(2550));

        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-2', $result->nextAction?->value);
    }

    /** `PayPal-Request-Id` is present, derived from the payment and the same on a retry; another payment has another one. */
    public function test_the_request_id_is_stable_between_retries(): void
    {
        $this->fakeOrder();
        $gateway = $this->gateway();

        $gateway->charge($this->cardCharge(2550));
        $gateway->charge($this->cardCharge(2550));
        $gateway->charge($this->cardCharge(2550, self::OTHER_PAYMENT));

        $ids = [];
        Http::assertSent(function (Request $request) use (&$ids): bool {
            if ($request->url() === self::BASE.'/v2/checkout/orders') {
                $ids[] = $request->header('PayPal-Request-Id')[0] ?? null;
            }

            return true;
        });
        $this->assertCount(3, $ids);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertNotSame($ids[0], $ids[2]);
        $this->assertSame('barberhub-'.self::PAYMENT.'-create', $ids[0]);
        $this->assertLessThanOrEqual(108, strlen($ids[0]));
    }

    /** The token is asked with the client and the secret (Basic, form), kept in the cache, and not asked again. */
    public function test_the_access_token_is_asked_once_and_cached(): void
    {
        $this->fakeOrder();
        $gateway = $this->gateway();

        $gateway->charge($this->cardCharge(2550));
        $gateway->charge($this->cardCharge(2550));

        $tokenKey = 'payments.paypal.token.'.sha1(self::BASE.'|client-id');
        $this->assertSame(self::TOKEN, Cache::get($tokenKey));
        $tokenCalls = 0;
        Http::assertSent(function (Request $request) use (&$tokenCalls): bool {
            if ($request->url() === self::BASE.'/v1/oauth2/token') {
                $tokenCalls++;
                $this->assertSame('POST', $request->method());
                $this->assertSame('client_credentials', $request['grant_type']);
                $this->assertTrue($request->hasHeader('Authorization', 'Basic '.base64_encode('client-id:client-secret')));
            }

            return true;
        });
        $this->assertSame(1, $tokenCalls);
    }

    /** A token of the cache is used without asking for another. */
    public function test_a_cached_token_is_used_without_asking_again(): void
    {
        Cache::put('payments.paypal.token.'.sha1(self::BASE.'|client-id'), 'CACHED', 600);
        Http::fake([self::BASE.'/v2/checkout/orders' => $this->orderResponse()]);

        $this->gateway()->charge($this->cardCharge(2550));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer CACHED'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/oauth2/token'));
    }

    /** Credentials that PayPal refuses are an error of ours: logged, and a 503 for the client with no detail. */
    public function test_refused_credentials_are_a_logged_communication_error(): void
    {
        $entries = [];
        Log::shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []) use (&$entries): void {
            $entries[] = [$level, $context];
        });
        Http::fake([self::BASE.'/v1/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401)]);

        try {
            $this->gateway()->charge($this->cardCharge(2550));
            $this->fail('The refusal was not reported.');
        } catch (GatewayUnavailableException $exception) {
            $this->assertNull($exception->created);
        }

        $this->assertCount(1, $entries);
        $this->assertSame('error', $entries[0][0]);
        $this->assertSame(['gateway' => 'paypal', 'operation' => 'token', 'status' => 401], array_intersect_key($entries[0][1], array_flip(['gateway', 'operation', 'status'])));
        $this->assertStringNotContainsString('client-secret', json_encode($entries[0][1], JSON_THROW_ON_ERROR));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/v2/'));
    }

    /** Before the order exists, a timeout, a lost connection or a 5xx is a communication error with no charge, and nothing is retried. */
    public function test_a_communication_error_before_the_order_carries_no_charge(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->assertCommunicationErrorWithoutCharge();

        Cache::flush();
        $this->freshHttp();
        Http::fake([self::BASE.'/v1/oauth2/token' => $this->tokenResponse(), self::BASE.'/v2/checkout/orders' => Http::response(['message' => 'down'], 503)]);
        $this->assertCommunicationErrorWithoutCharge();

        Http::assertSentCount(2);
    }

    /** An order PayPal does not accept (400 or 422) is a rejection of our data. */
    public function test_an_order_that_paypal_does_not_accept_is_rejected_as_a_request(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders' => Http::response(['name' => 'INVALID_REQUEST', 'message' => 'Request is not well-formed', 'details' => [['issue' => 'INVALID_PARAMETER_SYNTAX']]], 400),
        ]);

        $this->expectException(GatewayRequestRejectedException::class);
        $this->gateway()->charge($this->cardCharge(2550));
    }

    /** The order waiting for the customer is pending; a voided order is a failure. */
    public function test_the_status_query_translates_the_waiting_and_voided_states(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders/ORDER-1' => Http::sequence()
                ->push(['id' => 'ORDER-1', 'status' => 'CREATED'])
                ->push(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED'])
                ->push(['id' => 'ORDER-1', 'status' => 'VOIDED']),
        ]);
        $gateway = $this->gateway();

        $created = $gateway->status('ORDER-1', new PaymentId(self::PAYMENT));
        $waiting = $gateway->status('ORDER-1', new PaymentId(self::PAYMENT));
        $this->assertSame(PaymentStatus::Pending, $created->status);
        $this->assertSame(PaymentStatus::Pending, $waiting->status);
        $voided = $gateway->status('ORDER-1', new PaymentId(self::PAYMENT));
        $this->assertSame(PaymentStatus::Failed, $voided->status);
        $this->assertSame('voided', $voided->failureReason);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/capture'));
    }

    /** An approved order is captured and, when the capture is completed, the payment is approved and keeps the capture. */
    public function test_an_approved_order_is_captured_and_approved(): void
    {
        $this->fakeApprovedAndCaptured();

        $status = $this->gateway()->status('ORDER-1', new PaymentId(self::PAYMENT));

        $this->assertSame(PaymentStatus::Approved, $status->status);
        $this->assertSame('CAPTURE-1', $status->captureId);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/v2/checkout/orders/ORDER-1/capture'
            && $request->method() === 'POST'
            && $request->header('PayPal-Request-Id') === ['barberhub-'.self::PAYMENT.'-capture']);
    }

    /** A capture that PayPal declines is a rejection with its reason, not an error. */
    public function test_a_declined_capture_is_rejected_with_the_reason(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::BASE.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed.', 'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was either declined by the processor or bank, or it can\'t be used for this payment.']]], 422),
        ]);

        $status = $this->gateway()->status('ORDER-1', new PaymentId(self::PAYMENT));

        $this->assertSame(PaymentStatus::Rejected, $status->status);
        $this->assertStringContainsString('declined', (string) $status->failureReason);
        $this->assertNull($status->captureId);
    }

    /** Asking twice captures with the same idempotency key, so PayPal captures once; an order already captured is read as completed. */
    public function test_asking_twice_never_captures_twice(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders/ORDER-1' => Http::sequence()
                ->push(['id' => 'ORDER-1', 'status' => 'APPROVED'])
                ->push(['id' => 'ORDER-1', 'status' => 'APPROVED'])
                ->push($this->completedOrder()),
            self::BASE.'/v2/checkout/orders/ORDER-1/capture' => Http::sequence()
                ->push($this->completedOrder(), 201)
                ->push($this->completedOrder(), 200)
                ->push(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
        ]);
        $gateway = $this->gateway();

        $first = $gateway->status('ORDER-1', new PaymentId(self::PAYMENT));
        $second = $gateway->status('ORDER-1', new PaymentId(self::PAYMENT));

        $this->assertSame(PaymentStatus::Approved, $first->status);
        $this->assertSame(PaymentStatus::Approved, $second->status);
        $keys = [];
        Http::assertSent(function (Request $request) use (&$keys): bool {
            if (str_ends_with($request->url(), '/capture')) {
                $keys[] = $request->header('PayPal-Request-Id')[0] ?? null;
            }

            return true;
        });
        $this->assertSame(['barberhub-'.self::PAYMENT.'-capture', 'barberhub-'.self::PAYMENT.'-capture'], $keys);

        $this->freshHttp();
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders/ORDER-1' => Http::sequence()->push(['id' => 'ORDER-1', 'status' => 'APPROVED'])->push($this->completedOrder()),
            self::BASE.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422),
        ]);
        $third = $gateway->status('ORDER-1', new PaymentId(self::PAYMENT));
        $this->assertSame(PaymentStatus::Approved, $third->status);
        $this->assertSame('CAPTURE-1', $third->captureId);
    }

    /** A communication error while asking or capturing is an exception: the payment stays as it was and the next query resolves it. */
    public function test_a_communication_error_while_capturing_is_reported(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::BASE.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['message' => 'down'], 500),
        ]);

        $this->expectException(GatewayUnavailableException::class);
        $this->gateway()->status('ORDER-1', new PaymentId(self::PAYMENT));
    }

    /** A capture that is still pending stays pending. */
    public function test_a_pending_capture_stays_pending(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::BASE.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-1', 'status' => 'PENDING']]]]]], 201),
        ]);

        $this->assertSame(PaymentStatus::Pending, $this->gateway()->status('ORDER-1', new PaymentId(self::PAYMENT))->status);
    }

    /** Refunds are not implemented: the adapter says so and asks nothing of PayPal. */
    public function test_a_refund_is_not_supported(): void
    {
        try {
            $this->gateway()->refund('ORDER-1', Money::of(2550), new PaymentId(self::PAYMENT));
            $this->fail('The refund was not refused.');
        } catch (RefundNotSupportedException) {
            Http::assertNothingSent();
        }
    }

    /** PayPal admits the card and nothing else. */
    public function test_it_supports_only_the_card(): void
    {
        $gateway = $this->gateway();

        $this->assertSame(PaymentProvider::Paypal, $gateway->provider());
        $this->assertTrue($gateway->supports(PaymentMethod::Card));
        $this->assertFalse($gateway->supports(PaymentMethod::SinpeMovil));
    }

    /** The live API is refused when the adapter is built, whatever else is configured (PA-09). */
    public function test_the_live_api_is_never_accepted(): void
    {
        foreach (['https://api-m.paypal.com', 'https://api-m.paypal.com/', 'https://API.paypal.com'] as $url) {
            try {
                $this->gateway(baseUrl: $url);
                $this->fail("The live URL {$url} was accepted.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** A rate that is not a positive number is refused when the adapter is built. */
    public function test_the_exchange_rate_must_be_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->gateway(colonesPerDollar: 0);
    }

    /** The container builds the adapter from config/payments.php, with the sandbox by default, and refuses the live URL there too. */
    public function test_the_container_builds_the_adapter_from_the_configuration(): void
    {
        config(['payments.paypal.client_id' => 'client-id', 'payments.paypal.client_secret' => 'client-secret']);
        $this->assertSame(PaymentProvider::Paypal, $this->app->make(PayPalGateway::class)->provider());
        $this->assertSame('https://api-m.sandbox.paypal.com', config('payments.paypal.base_url'));
        $this->assertSame('500', (string) config('payments.paypal.crc_per_usd'));

        config(['payments.paypal.base_url' => 'https://api-m.paypal.com']);
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(PayPalGateway::class);
    }

    /** The timeouts are 10 s overall and 5 s to connect. */
    public function test_the_timeouts_are_defined(): void
    {
        $this->assertSame(10, PayPalGateway::TIMEOUT_SECONDS);
        $this->assertSame(5, PayPalGateway::CONNECT_TIMEOUT_SECONDS);
    }

    /** Forget the answers faked so far and the requests recorded, keeping the ban on real requests. */
    private function freshHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    private function gateway(string $baseUrl = self::BASE, string|int $colonesPerDollar = 500): PayPalGateway
    {
        return new PayPalGateway($this->app->make(Factory::class), $this->app->make(CacheRepository::class), 'client-id', 'client-secret', $baseUrl, 'https://app.test/api/v1/pagos/retorno', 'https://app.test/cancelado', $colonesPerDollar);
    }

    private function cardCharge(int $colones, string $paymentId = self::PAYMENT): ChargeRequest
    {
        return new ChargeRequest(new PaymentId($paymentId), new ReservationId(self::RESERVATION), Money::of($colones), PaymentMethod::Card, PaymentDetails::card('unused'));
    }

    private function assertCommunicationErrorWithoutCharge(): void
    {
        try {
            $this->gateway()->charge($this->cardCharge(2550));
            $this->fail('The communication error was not reported.');
        } catch (GatewayUnavailableException $exception) {
            $this->assertNull($exception->created);
        }
    }

    private function tokenResponse(): mixed
    {
        return Http::response(['scope' => 'https://uri.paypal.com/services/payments/payment', 'access_token' => self::TOKEN, 'token_type' => 'Bearer', 'app_id' => 'APP-1', 'expires_in' => 32400, 'nonce' => 'n']);
    }

    private function orderResponse(): mixed
    {
        return Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'payment_source' => ['paypal' => []], 'links' => [
            ['href' => self::BASE.'/v2/checkout/orders/ORDER-1', 'rel' => 'self', 'method' => 'GET'],
            ['href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1', 'rel' => 'payer-action', 'method' => 'GET'],
        ]], 200);
    }

    private function fakeOrder(): void
    {
        Http::fake([self::BASE.'/v1/oauth2/token' => $this->tokenResponse(), self::BASE.'/v2/checkout/orders' => $this->orderResponse()]);
    }

    private function fakeApprovedAndCaptured(): void
    {
        Http::fake([
            self::BASE.'/v1/oauth2/token' => $this->tokenResponse(),
            self::BASE.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::BASE.'/v2/checkout/orders/ORDER-1/capture' => Http::response($this->completedOrder(), 201),
        ]);
    }

    /** @return array<string, mixed> */
    private function completedOrder(): array
    {
        return ['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['reference_id' => self::PAYMENT, 'payments' => ['captures' => [['id' => 'CAPTURE-1', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '5.10']]]]]]];
    }
}
