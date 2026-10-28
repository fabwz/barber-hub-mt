<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Infrastructure\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Src\Payments\Payments\Application\Contracts\PaymentGateway;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Shared\Infrastructure\Http\Middleware\AssignRequestId;

/**
 * What every adapter that talks to its provider over HTTP shares: the one place that calls, classifies the failures and logs them.
 *
 * - A timeout, a lost connection or a 5xx is a communication error (`GatewayUnavailableException`, 503).
 * - A 401 or 403 means our credentials are wrong: a configuration error of ours. It is logged as an error and also becomes
 *   `GatewayUnavailableException`, so the client sees the same generic 503 and no detail.
 * - Any other 4xx is returned to the adapter. It either reads it as a decision of the provider (a declined card, a refused
 *   refund) or calls `requireSuccess`, which turns it into `GatewayRequestRejectedException`: our data is of no use to the provider (422).
 *
 * Every failure is logged with the gateway, the operation, the HTTP status, the error body of the provider (cut short), the
 * identifier of the payment and the identifier of the current request. Neither the headers, nor the keys, nor what is sent are ever logged.
 */
abstract readonly class HttpPaymentGateway implements PaymentGateway
{
    /** Characters of the error body of the provider that reach the log. */
    private const int LOGGED_BODY_LIMIT = 500;

    public function __construct(protected Factory $http) {}

    /** The short name of the gateway in the log, for example `onvo`. */
    abstract protected function name(): string;

    /**
     * The secrets of the adapter (its keys): they are removed from anything that reaches the log.
     *
     * @return list<string>
     */
    abstract protected function secrets(): array;

    /** The client with the base URL, the authentication and the timeouts of the provider. */
    abstract protected function prepare(Factory $http): PendingRequest;

    /**
     * Call the provider once, without retries (a charge that repeats itself could charge twice).
     *
     * @param  array<string, mixed>  $body
     *
     * @throws GatewayUnavailableException On a timeout, a failed connection, a 5xx or a refusal of our credentials.
     */
    protected function send(string $operation, PaymentId $paymentId, string $method, string $path, array $body = []): Response
    {
        try {
            $response = $this->prepare($this->http)->send($method, $path, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException $exception) {
            $this->logFailure('warning', 'The payment gateway could not be reached.', $operation, $paymentId, null, $exception->getMessage());

            throw GatewayUnavailableException::because("{$this->name()} could not be reached.", $exception);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            $this->logFailure('error', 'The payment gateway refused our credentials: check the keys of the gateway in the configuration.', $operation, $paymentId, $status, $response->body());

            throw GatewayUnavailableException::because("{$this->name()} refused the credentials (HTTP {$status}).");
        }

        $this->logFailure('warning', 'The payment gateway answered with an error.', $operation, $paymentId, $status, $response->body());

        if ($response->serverError()) {
            throw GatewayUnavailableException::because("{$this->name()} answered HTTP {$status}.");
        }

        return $response;
    }

    /**
     * Give back the answer when it is a success; a 4xx is the provider not accepting what it was sent.
     *
     * @throws GatewayRequestRejectedException
     */
    protected function requireSuccess(Response $response): Response
    {
        return $response->successful() ? $response : throw GatewayRequestRejectedException::because("{$this->name()} answered HTTP {$response->status()}.");
    }

    /** Write one failure of the provider to the log, with no secret in it. */
    private function logFailure(string $level, string $message, string $operation, PaymentId $paymentId, ?int $status, string $errorBody): void
    {
        Log::log($level, $message, [
            'gateway' => $this->name(),
            'operation' => $operation,
            'status' => $status,
            'provider_error' => Str::limit(str_replace($this->secrets(), '[redacted]', $errorBody), self::LOGGED_BODY_LIMIT, '...'),
            'payment_id' => $paymentId->value,
            'request_id' => Context::get(AssignRequestId::CONTEXT_KEY),
        ]);
    }
}
