<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Gateways;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\ChargeResult;
use Src\Payments\Payments\Application\DTOs\GatewayStatus;
use Src\Payments\Payments\Application\DTOs\NextAction;
use Src\Payments\Payments\Application\DTOs\RefundResult;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Application\Exceptions\RefundNotSupportedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Shared\Domain\ValueObjects\Money;

/**
 * The adapter of PayPal (RF-20, RL-03), over the Orders API v2: create the order, let the customer approve it on the page of
 * PayPal and capture it. It authenticates with OAuth2 (client credentials) and keeps the access token in the cache until
 * shortly before it expires. PayPal charges in dollars, so the conversion from colones lives here and nowhere else. It has no
 * retries, and the two calls that move state (create and capture) carry a `PayPal-Request-Id` derived from our payment, so
 * asking twice never creates a second order nor captures twice. It never talks to the live API: every payment of this system
 * runs in the sandbox (PA-09). Refunds are not implemented in this stage (see docs/architecture.md).
 */
final readonly class PayPalGateway extends HttpPaymentGateway
{
    /** Seconds the whole call may take, and the part of it for connecting. */
    public const int TIMEOUT_SECONDS = 10;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    /** Currency of every charge (PayPal does not charge in colones) and units of the minimum denomination in a dollar. */
    private const string CURRENCY = 'USD';

    private const int MINOR_UNITS_PER_DOLLAR = 100;

    /** Hosts of the live API of PayPal, which the adapter refuses. */
    private const array LIVE_HOSTS = ['api-m.paypal.com', 'api.paypal.com'];

    /** Seconds before the expiry of the access token at which it stops being used. */
    private const int TOKEN_MARGIN_SECONDS = 60;

    /** Issues of a refused capture that are a decision of PayPal about the money of the customer, not a mistake of ours. */
    private const array DECLINE_ISSUES = [
        'INSTRUMENT_DECLINED',
        'TRANSACTION_REFUSED',
        'PAYER_CANNOT_PAY',
        'PAYER_ACCOUNT_RESTRICTED',
        'PAYER_ACCOUNT_LOCKED_OR_CLOSED',
    ];

    private string $exchangeRate;

    /**
     * Receive the HTTP client, the cache for the access token and the configuration of PayPal.
     *
     * @throws InvalidArgumentException When the base URL is the live API or the exchange rate is not positive.
     */
    public function __construct(
        Factory $http,
        private Cache $cache,
        private string $clientId,
        private string $clientSecret,
        private string $baseUrl,
        private string $returnUrl,
        private string $cancelUrl,
        string|float|int $colonesPerDollar,
    ) {
        parent::__construct($http);

        if (in_array(strtolower((string) parse_url($baseUrl, PHP_URL_HOST)), self::LIVE_HOSTS, true)) {
            throw new InvalidArgumentException('The live API of PayPal is never accepted: every payment runs in the sandbox.');
        }

        if (! is_numeric($colonesPerDollar) || (float) $colonesPerDollar <= 0) {
            throw new InvalidArgumentException('The exchange rate of PayPal must be a positive number of colones per dollar.');
        }

        $this->exchangeRate = number_format((float) $colonesPerDollar, 4, '.', '');
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paypal;
    }

    /** PayPal admits only the card here (RF-20); the other ways of paying of PayPal are out of scope. */
    public function supports(PaymentMethod $method): bool
    {
        return $method === PaymentMethod::Card;
    }

    /** The customer pays on the page of PayPal: nothing is asked of them before. */
    public function requiredInput(PaymentMethod $method): array
    {
        return [];
    }

    /**
     * Create the order with the amount converted to dollars. The customer pays on the page of PayPal, as a guest with a card, so
     * the result is always pending with the address to go to. The call carries a stable `PayPal-Request-Id`, so repeating it
     * returns the same order. There is nothing after it that can fail with the order already created.
     */
    public function charge(ChargeRequest $request): ChargeResult
    {
        if ($request->method !== PaymentMethod::Card) {
            throw new InvalidArgumentException('PayPal charges only with the card.');
        }

        $cents = max(1, (int) round($request->amount->colones * self::MINOR_UNITS_PER_DOLLAR / (float) $this->exchangeRate));
        $response = $this->requireSuccess($this->call('create_order', $request->paymentId, 'POST', '/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $request->paymentId->value,
                'custom_id' => $request->reservationId->value,
                'description' => 'BarberHub',
                'amount' => ['currency_code' => self::CURRENCY, 'value' => $this->decimal($cents)],
            ]],
            'payment_source' => ['paypal' => ['experience_context' => array_filter([
                'brand_name' => 'BarberHub',
                'landing_page' => 'GUEST_CHECKOUT',
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'return_url' => $this->returnUrl,
                'cancel_url' => $this->cancelUrl,
            ], fn (string $value): bool => $value !== '')]],
        ], ['PayPal-Request-Id' => $this->requestId($request->paymentId, 'create')]));

        $orderId = $response->json('id');

        if (! is_string($orderId) || $orderId === '') {
            throw GatewayUnavailableException::because('PayPal answered the order without an identifier.');
        }

        return new ChargeResult(
            $orderId,
            ProviderAmount::of(self::CURRENCY, $cents, $this->exchangeRate),
            PaymentStatus::Pending,
            null,
            $this->approvalLinkOf($response->json() ?? []),
        );
    }

    /**
     * Ask PayPal for the order. Created or waiting for the customer is pending; approved by the customer is captured now
     * (with a stable `PayPal-Request-Id`, so asking again never captures twice); completed is approved and keeps the
     * identifier of the capture; a refused capture is a rejection with its reason; a voided order is a failure.
     */
    public function status(string $providerPaymentId, PaymentId $paymentId): GatewayStatus
    {
        $order = $this->requireSuccess($this->call('status', $paymentId, 'GET', "/v2/checkout/orders/{$providerPaymentId}"))->json() ?? [];

        if (($order['status'] ?? null) === 'APPROVED') {
            return $this->capture($providerPaymentId, $paymentId);
        }

        return $this->interpret($order);
    }

    /** Refunds are not implemented in this stage: the endpoint answers that the operation is not supported. */
    public function refund(string $providerPaymentId, Money $amount, PaymentId $paymentId): RefundResult
    {
        throw RefundNotSupportedException::create();
    }

    /** Capture the approved order. A refusal of the money is a result; an order captured before is read again. */
    private function capture(string $orderId, PaymentId $paymentId): GatewayStatus
    {
        $response = $this->call('capture', $paymentId, 'POST', "/v2/checkout/orders/{$orderId}/capture", [], ['PayPal-Request-Id' => $this->requestId($paymentId, 'capture')]);

        if ($response->successful()) {
            return $this->interpret($response->json() ?? []);
        }

        $issue = $this->issueOf($response);

        if ($response->status() === 422 && $issue === 'ORDER_ALREADY_CAPTURED') {
            return $this->interpret($this->requireSuccess($this->call('status', $paymentId, 'GET', "/v2/checkout/orders/{$orderId}"))->json() ?? []);
        }

        if ($response->status() === 422 && in_array($issue, self::DECLINE_ISSUES, true)) {
            return new GatewayStatus(PaymentStatus::Rejected, $this->messageOf($response));
        }

        $this->requireSuccess($response);

        return new GatewayStatus(PaymentStatus::Pending);
    }

    /**
     * Translate an order: `COMPLETED` is approved if its capture is completed (pending while the capture is, rejected if it was
     * declined); `VOIDED` is a failure; any other state keeps waiting.
     *
     * @param  array<mixed>  $order
     */
    private function interpret(array $order): GatewayStatus
    {
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;
        $captureId = is_array($capture) && is_string($capture['id'] ?? null) ? $capture['id'] : null;

        return match ($order['status'] ?? null) {
            'COMPLETED' => match (is_array($capture) ? ($capture['status'] ?? null) : null) {
                'PENDING' => new GatewayStatus(PaymentStatus::Pending),
                'DECLINED', 'FAILED' => new GatewayStatus(PaymentStatus::Rejected, 'declined'),
                default => new GatewayStatus(PaymentStatus::Approved, null, $captureId),
            },
            'VOIDED' => new GatewayStatus(PaymentStatus::Failed, 'voided'),
            default => new GatewayStatus(PaymentStatus::Pending),
        };
    }

    /**
     * The address where the customer approves the order: `payer-action` when the order was created with a payment source,
     * `approve` otherwise.
     *
     * @param  array<mixed>  $order
     */
    private function approvalLinkOf(array $order): ?NextAction
    {
        foreach (['payer-action', 'approve'] as $rel) {
            foreach (is_array($order['links'] ?? null) ? $order['links'] : [] as $link) {
                if (is_array($link) && ($link['rel'] ?? null) === $rel && is_string($link['href'] ?? null)) {
                    return NextAction::redirectTo($link['href']);
                }
            }
        }

        return null;
    }

    /** The first issue PayPal names in an error, or null. */
    private function issueOf(Response $response): ?string
    {
        $issue = $response->json('details.0.issue');

        return is_string($issue) ? $issue : null;
    }

    /** The reason PayPal gives for an error: the description of its first detail, its issue or its message. */
    private function messageOf(Response $response): ?string
    {
        foreach (['details.0.description', 'details.0.issue', 'message'] as $key) {
            $value = $response->json($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** The idempotency key of an operation of a payment: stable, so a repeated call is the same call for PayPal (at most 108 characters). */
    private function requestId(PaymentId $paymentId, string $operation): string
    {
        return "barberhub-{$paymentId->value}-{$operation}";
    }

    /** An amount in cents as the decimal text PayPal takes: a string with two decimals, never a number. */
    private function decimal(int $cents): string
    {
        return number_format($cents / self::MINOR_UNITS_PER_DOLLAR, 2, '.', '');
    }

    /**
     * Call the API with the access token.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function call(string $operation, PaymentId $paymentId, string $method, string $path, array $body = [], array $headers = []): Response
    {
        $client = $this->prepare($this->http)->withToken($this->accessToken($paymentId))->withHeaders($headers);

        return $this->dispatch($client, $operation, $paymentId, $method, $path, $body === [] ? [] : ['json' => $body]);
    }

    /**
     * The OAuth2 access token: from the cache while it is valid, otherwise asked with the client and the secret and kept until
     * shortly before it expires.
     *
     * @throws GatewayUnavailableException When PayPal does not give a token.
     */
    private function accessToken(PaymentId $paymentId): string
    {
        $key = 'payments.paypal.token.'.sha1($this->baseUrl.'|'.$this->clientId);
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $client = $this->prepare($this->http)->withBasicAuth($this->clientId, $this->clientSecret)->asForm();
        $response = $this->dispatch($client, 'token', $paymentId, 'POST', '/v1/oauth2/token', ['form_params' => ['grant_type' => 'client_credentials']]);
        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_int($expiresIn)) {
            throw GatewayUnavailableException::because('PayPal did not give an access token.');
        }

        $this->cache->put($key, $token, max(1, $expiresIn - self::TOKEN_MARGIN_SECONDS));

        return $token;
    }

    protected function name(): string
    {
        return 'paypal';
    }

    protected function secrets(): array
    {
        return array_values(array_filter([$this->clientSecret, $this->clientId], fn (string $secret): bool => $secret !== ''));
    }

    /** The client of PayPal: base URL, JSON, timeouts and no retries; the authentication is added per call. */
    protected function prepare(Factory $http): PendingRequest
    {
        return $http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS);
    }
}
