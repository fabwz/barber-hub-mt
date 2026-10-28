<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\PaymentDetails;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Application\Exceptions\GatewayUnavailableException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;
use Src\Shared\Domain\ValueObjects\Money;
use Tests\TestCase;
use Throwable;

/**
 * How the failures of a provider are classified and logged by the base of every HTTP gateway, shown with ONVO Pay: a timeout,
 * a lost connection or a 5xx is a communication error (503); a 400 or 422 means our data is of no use to the provider (422);
 * a 401 or 403 is our credentials (a generic 503, logged as an error). Every failure leaves one entry in the log, without keys.
 */
final class GatewayErrorClassificationTest extends TestCase
{
    private const string BASE = 'https://api.onvopay.com/v1';

    private const string SECRET = 'onvo_test_secret_key_do_not_log';

    private const string PAYMENT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f01';

    private const string REQUEST_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f99';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $entries = [];

    /** No request leaves the process, and the log is captured. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Context::add('request_id', self::REQUEST_ID);
        Log::shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []): void {
            $this->entries[] = [$level, $message, $context];
        });
    }

    /** A timeout or a lost connection is a communication error, logged as a warning with no HTTP status. */
    public function test_a_connection_failure_is_a_communication_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->assertInstanceOf(GatewayUnavailableException::class, $this->failureOf(fn () => $this->gateway()->status('pi_1', new PaymentId(self::PAYMENT))));
        $this->assertLogged('warning', 'status', null);
    }

    /** A 5xx of the provider is a communication error, and its body is in the log. */
    public function test_a_server_error_is_a_communication_error(): void
    {
        Http::fake([self::BASE.'/payment-intents/pi_1' => Http::sequence()->push(['message' => 'upstream down'], 500)->push(['message' => 'upstream down'], 502)->push(['message' => 'upstream down'], 503)]);

        foreach ([500, 502, 503] as $status) {
            $this->entries = [];

            $this->assertInstanceOf(GatewayUnavailableException::class, $this->failureOf(fn () => $this->gateway()->status('pi_1', new PaymentId(self::PAYMENT))));
            $this->assertLogged('warning', 'status', $status, 'upstream down');
        }
    }

    /** A 400 or 422 while creating the SINPE Móvil method or the intent is a request the provider did not accept, and no charge is carried. */
    public function test_a_bad_request_is_a_rejected_request(): void
    {
        Http::fake([self::BASE.'/payment-methods' => Http::sequence()->push(['message' => ['identificationType must be a number']], 400)->push(['message' => ['identificationType must be a number']], 422)]);

        foreach ([400, 422] as $status) {
            $this->entries = [];

            $this->assertInstanceOf(GatewayRequestRejectedException::class, $this->failureOf(fn () => $this->gateway()->charge($this->sinpeCharge())));
            $this->assertLogged('warning', 'create_sinpe_method', $status, 'identificationType must be a number');
        }

        Http::fake([self::BASE.'/payment-intents' => Http::response(['message' => 'amount too small'], 400)]);
        $this->entries = [];

        $this->assertInstanceOf(GatewayRequestRejectedException::class, $this->failureOf(fn () => $this->gateway()->charge($this->cardCharge())));
        $this->assertLogged('warning', 'create_intent', 400, 'amount too small');
    }

    /** A 4xx on the status query is a request the provider did not accept too. */
    public function test_a_bad_status_query_is_a_rejected_request(): void
    {
        Http::fake([self::BASE.'/payment-intents/pi_1' => Http::response(['message' => 'not found'], 404)]);

        $this->assertInstanceOf(GatewayRequestRejectedException::class, $this->failureOf(fn () => $this->gateway()->status('pi_1', new PaymentId(self::PAYMENT))));
        $this->assertLogged('warning', 'status', 404, 'not found');
    }

    /** A 401 or 403 is our configuration: a generic communication error for the client, and an unmistakable error in the log. */
    public function test_refused_credentials_are_a_configuration_error(): void
    {
        Http::fake([self::BASE.'/payment-intents' => Http::sequence()->push(['message' => 'Invalid API key'], 401)->push(['message' => 'Invalid API key'], 403)]);

        foreach ([401, 403] as $status) {
            $this->entries = [];

            $this->assertInstanceOf(GatewayUnavailableException::class, $this->failureOf(fn () => $this->gateway()->charge($this->cardCharge())));
            $this->assertLogged('error', 'create_intent', $status, 'Invalid API key');
            $this->assertStringContainsString('refused our credentials', $this->entries[0][1]);
        }
    }

    /** A decline while confirming is a decision about the payment, not a failure to classify: it stays a result, but it is logged too. */
    public function test_a_refusal_when_confirming_stays_a_result(): void
    {
        Http::fake([
            self::BASE.'/payment-intents' => Http::response(['id' => 'pi_9']),
            self::BASE.'/payment-intents/pi_9/confirm' => Http::response(['message' => 'Card declined'], 400),
        ]);

        $result = $this->gateway()->charge($this->cardCharge());

        $this->assertSame('Card declined', $result->failureReason);
        $this->assertLogged('warning', 'confirm', 400, 'Card declined');
    }

    /** The log has the gateway, the operation, the status, the error of the provider (cut short), the payment and the request, and never the key. */
    public function test_the_log_has_the_context_and_never_the_key(): void
    {
        Http::fake([self::BASE.'/payment-intents' => Http::response(['message' => 'Bad key '.self::SECRET.' '.str_repeat('x', 2000)], 401)]);

        $this->failureOf(fn () => $this->gateway()->charge($this->cardCharge()));

        $this->assertCount(1, $this->entries);
        [, , $context] = $this->entries[0];
        $this->assertSame('onvo', $context['gateway']);
        $this->assertSame('create_intent', $context['operation']);
        $this->assertSame(401, $context['status']);
        $this->assertSame(self::PAYMENT, $context['payment_id']);
        $this->assertSame(self::REQUEST_ID, $context['request_id']);
        $this->assertLessThanOrEqual(503, strlen($context['provider_error']));
        $this->assertStringContainsString('[redacted]', $context['provider_error']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->entries, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsStringIgnoringCase('Bearer', json_encode($this->entries, JSON_THROW_ON_ERROR));
    }

    /** A refusal of a refund stays a result of the provider; a 5xx on it is a communication error. */
    public function test_refund_failures_are_classified(): void
    {
        Http::fake([self::BASE.'/refunds' => Http::sequence()->push(['message' => 'Already refunded'], 400)->push(['message' => 'down'], 500)]);
        $gateway = $this->gateway();

        $this->assertFalse($gateway->refund('pi_1', Money::of(5000), new PaymentId(self::PAYMENT))->accepted);
        $this->assertLogged('warning', 'refund', 400, 'Already refunded');

        $this->assertInstanceOf(GatewayUnavailableException::class, $this->failureOf(fn () => $gateway->refund('pi_1', Money::of(5000), new PaymentId(self::PAYMENT))));
    }

    /** Assert that the log has an entry of the level for the operation, with the status and (when given) the provider's text. */
    private function assertLogged(string $level, string $operation, ?int $status, ?string $text = null): void
    {
        $matching = array_filter($this->entries, fn (array $entry): bool => $entry[0] === $level && $entry[2]['operation'] === $operation && $entry[2]['status'] === $status);

        $this->assertNotEmpty($matching, "No {$level} entry for {$operation} with status ".var_export($status, true));
        $context = array_values($matching)[0][2];
        $this->assertSame('onvo', $context['gateway']);
        $this->assertSame(self::PAYMENT, $context['payment_id']);
        $this->assertSame(self::REQUEST_ID, $context['request_id']);
        if ($text !== null) {
            $this->assertStringContainsString($text, $context['provider_error']);
        }
    }

    /** The exception the call throws. */
    private function failureOf(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $exception) {
            return $exception;
        }

        $this->fail('The call did not fail.');
    }

    private function gateway(): OnvoGateway
    {
        return new OnvoGateway($this->app->make(Factory::class), self::SECRET, self::BASE, '', '+506 70196686');
    }

    private function cardCharge(): ChargeRequest
    {
        return new ChargeRequest(new PaymentId(self::PAYMENT), new ReservationId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f02'), Money::of(5000), PaymentMethod::Card, PaymentDetails::card('pm_1'));
    }

    private function sinpeCharge(): ChargeRequest
    {
        return new ChargeRequest(new PaymentId(self::PAYMENT), new ReservationId('0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f02'), Money::of(2100), PaymentMethod::SinpeMovil, PaymentDetails::sinpeMovil('+50688888888', '01-1393-1919', '0', 'Ana Rojas', 'ana@barberhub.test'));
    }
}
