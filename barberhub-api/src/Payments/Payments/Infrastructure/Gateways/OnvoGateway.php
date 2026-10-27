<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\ChargeResult;
use Src\Payments\Payments\Application\DTOs\GatewayStatus;
use Src\Payments\Payments\Application\DTOs\NextAction;
use Src\Payments\Payments\Application\DTOs\RefundResult;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ProviderAmount;
use Src\Shared\Domain\ValueObjects\Money;

/**
 * The adapter of ONVO Pay (RF-20, RL-03). It talks to its REST API with the HTTP client of Laravel, authenticated with the
 * secret key as a bearer token, and converts everything on its side: the port speaks colones, ONVO speaks the minimum unit of
 * the currency (₡5.000 is 500000). It has no retries: a charge that is repeated by itself could charge twice. It never
 * accepts a live key, because every payment of this system runs in the test mode (PA-09).
 */
final readonly class OnvoGateway implements PaymentGateway
{
    /** Seconds the whole call may take, and the part of it for connecting. */
    public const int TIMEOUT_SECONDS = 10;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    /** Currency of every charge and units of the minimum denomination in a colon. */
    private const string CURRENCY = 'CRC';

    private const int MINOR_UNITS_PER_COLON = 100;

    private const string LIVE_KEY_PREFIX = 'onvo_live_';

    /**
     * Receive the HTTP client and the configuration of ONVO Pay.
     *
     * @throws InvalidArgumentException When the key is a live one.
     */
    public function __construct(
        private Factory $http,
        private string $secretKey,
        private string $baseUrl,
        private string $returnUrl,
        private string $sinpeDestination,
    ) {
        if (str_starts_with($secretKey, self::LIVE_KEY_PREFIX)) {
            throw new InvalidArgumentException('A live key of ONVO Pay is never accepted: every payment runs in the test mode.');
        }
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Onvo;
    }

    /** ONVO Pay admits the card and SINPE Móvil (RF-20). */
    public function supports(PaymentMethod $method): bool
    {
        return in_array($method, [PaymentMethod::Card, PaymentMethod::SinpeMovil], true);
    }

    /**
     * Create the payment intent and confirm it with the method of the customer. A card arrives as the identifier of the
     * payment method the browser created with the public key; the SINPE Móvil method is created here, before the intent, so
     * a failure creating it leaves no charge behind. A failure after the intent exists carries the intent with it.
     */
    public function charge(ChargeRequest $request): ChargeResult
    {
        $details = $request->details ?? throw new InvalidArgumentException('ONVO Pay needs the details of the method.');
        $isSinpe = $request->method === PaymentMethod::SinpeMovil;
        $methodId = $isSinpe ? $this->createSinpeMethod($details->phone, $details->identification, $details->identificationType, $details->payerName, $details->payerEmail) : (string) $details->cardToken;

        $minorUnits = $request->amount->colones * self::MINOR_UNITS_PER_COLON;
        $intent = $this->send('POST', '/payment-intents', [
            'amount' => $minorUnits,
            'currency' => self::CURRENCY,
            'description' => 'BarberHub',
            'metadata' => ['paymentId' => $request->paymentId->value, 'reservationId' => $request->reservationId->value],
        ]);
        $intentId = $intent->successful() ? $intent->json('id') : null;

        if (! is_string($intentId) || $intentId === '') {
            throw GatewayUnavailableException::because("ONVO Pay did not create the charge (HTTP {$intent->status()}).");
        }

        $providerAmount = ProviderAmount::of(self::CURRENCY, $minorUnits);

        try {
            $confirmation = $this->send('POST', "/payment-intents/{$intentId}/confirm", array_filter([
                'paymentMethodId' => $methodId,
                'returnUrl' => $isSinpe ? '' : $this->returnUrl,
            ], fn (string $value): bool => $value !== ''));
        } catch (GatewayUnavailableException $exception) {
            throw GatewayUnavailableException::afterCharge(new ChargeResult($intentId, $providerAmount, PaymentStatus::Pending), $exception->getMessage(), $exception);
        }

        if ($confirmation->clientError()) {
            return new ChargeResult($intentId, $providerAmount, PaymentStatus::Rejected, $this->messageOf($confirmation));
        }

        [$status, $reason, $nextAction] = $this->interpret($confirmation->json() ?? []);

        if ($isSinpe && $status === PaymentStatus::Pending) {
            $nextAction = NextAction::instructions($this->sinpeDestination, $request->amount);
        }

        return new ChargeResult($intentId, $providerAmount, $status, $reason, $nextAction);
    }

    /** Ask ONVO Pay for the payment intent and translate its state. */
    public function status(string $providerPaymentId): GatewayStatus
    {
        $response = $this->send('GET', "/payment-intents/{$providerPaymentId}");

        if (! $response->successful()) {
            throw GatewayUnavailableException::because("ONVO Pay did not answer the status (HTTP {$response->status()}).");
        }

        [$status, $reason] = $this->interpret($response->json() ?? []);

        return new GatewayStatus($status, $reason);
    }

    /** Ask ONVO Pay to refund the amount of the payment intent, in the minimum unit. */
    public function refund(string $providerPaymentId, Money $amount): RefundResult
    {
        $response = $this->send('POST', '/refunds', [
            'paymentIntentId' => $providerPaymentId,
            'amount' => $amount->colones * self::MINOR_UNITS_PER_COLON,
        ]);
        $refundId = $response->json('id');

        if ($response->clientError()) {
            return RefundResult::refused($this->messageOf($response));
        }

        if (! $response->successful() || ! is_string($refundId) || in_array($response->json('status'), ['failed', 'canceled'], true)) {
            return RefundResult::refused($this->messageOf($response));
        }

        return RefundResult::accepted($refundId);
    }

    /** Create the SINPE Móvil payment method with the phone and identification of the customer and the name and email of the account; return its identifier. */
    private function createSinpeMethod(?string $phone, ?string $identification, ?string $identificationType, ?string $name, ?string $email): string
    {
        $response = $this->send('POST', '/payment-methods', [
            'type' => 'mobile_number',
            'mobileNumber' => ['identification' => $identification, 'identificationType' => $identificationType, 'number' => $phone],
            'billing' => ['name' => $name, 'email' => $email],
        ]);
        $id = $response->successful() ? $response->json('id') : null;

        return is_string($id) && $id !== '' ? $id : throw GatewayUnavailableException::because("ONVO Pay did not create the SINPE Móvil method (HTTP {$response->status()}).");
    }

    /**
     * Translate a payment intent: `succeeded` is approved; `requires_payment_method` with an error is a refusal (with its
     * reason); `requires_action` waits for the customer on a page (3DS); `processing` and the rest keep waiting; `canceled`
     * is a failure.
     *
     * @param  array<mixed>  $intent
     * @return array{PaymentStatus, string|null, NextAction|null}
     */
    private function interpret(array $intent): array
    {
        $error = $intent['lastPaymentError'] ?? null;
        $url = $intent['nextAction']['redirectToUrl']['url'] ?? null;

        return match ($intent['status'] ?? null) {
            'succeeded' => [PaymentStatus::Approved, null, null],
            'requires_payment_method' => is_array($error)
                ? [PaymentStatus::Rejected, is_string($error['message'] ?? null) ? $error['message'] : ($error['code'] ?? null), null]
                : [PaymentStatus::Pending, null, null],
            'requires_action' => [PaymentStatus::Pending, null, is_string($url) ? NextAction::redirectTo($url) : null],
            'canceled' => [PaymentStatus::Failed, 'canceled', null],
            default => [PaymentStatus::Pending, null, null],
        };
    }

    /** The message ONVO Pay gives for an error, or null. */
    private function messageOf(Response $response): ?string
    {
        $message = $response->json('message');

        return match (true) {
            is_string($message) => $message,
            is_array($message) && is_string($message[0] ?? null) => $message[0],
            default => null,
        };
    }

    /**
     * Call the API with the timeouts and without retries. A connection that fails or a 5xx is a communication error; any
     * other answer is returned for the caller to read.
     *
     * @param  array<string, mixed>  $body
     *
     * @throws GatewayUnavailableException
     */
    private function send(string $method, string $path, array $body = []): Response
    {
        try {
            $response = $this->http
                ->baseUrl(rtrim($this->baseUrl, '/'))
                ->withToken($this->secretKey)
                ->acceptJson()
                ->asJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->send($method, $path, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $exception) {
            Log::warning('ONVO Pay could not be reached.', ['method' => $method, 'path' => $path]);

            throw GatewayUnavailableException::because('ONVO Pay could not be reached.', $exception);
        }

        if ($response->serverError()) {
            Log::warning('ONVO Pay answered with an error.', ['method' => $method, 'path' => $path, 'status' => $response->status()]);

            throw GatewayUnavailableException::because("ONVO Pay answered HTTP {$response->status()}.");
        }

        return $response;
    }
}
