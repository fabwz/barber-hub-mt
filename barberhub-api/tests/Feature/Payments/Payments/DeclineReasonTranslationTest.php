<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Payments\Payments\Application\DTOs\ChargeRequest;
use Src\Payments\Payments\Application\DTOs\PaymentDetails;
use Src\Payments\Payments\Application\Exceptions\GatewayRequestRejectedException;
use Src\Payments\Payments\Domain\ValueObjects\PaymentId;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentStatus;
use Src\Payments\Payments\Domain\ValueObjects\ReservationId;
use Src\Payments\Payments\Infrastructure\Gateways\DeclineReasons;
use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;
use Src\Payments\Payments\Infrastructure\Gateways\PayPalGateway;
use Src\Shared\Domain\ValueObjects\Money;
use Tests\TestCase;

/**
 * The reason of a refused payment always leaves the adapters in Spanish: the known reasons of each gateway are translated, anything
 * else is the generic sentence, and the text of the provider only reaches the log, without card numbers or keys (RNF-11).
 */
final class DeclineReasonTranslationTest extends TestCase
{
    private const string ONVO = 'https://api.onvopay.com/v1';

    private const string PAYPAL = 'https://api-m.sandbox.paypal.com';

    private const string SECRET = 'onvo_test_secret_key_do_not_log';

    private const string PAYMENT = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f01';

    private const string RESERVATION = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f02';

    private const string REQUEST_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0f99';

    private const string CANCELED = 'El pago fue cancelado. Intente de nuevo.';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
        Context::add('request_id', self::REQUEST_ID);
        Log::shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []): void {
            $this->entries[] = [$level, $message, $context];
        });
    }

    /** The test card of ONVO Pay (4000 0000 0000 0002) answers in English and the customer reads it in Spanish. */
    public function test_the_onvo_test_card_is_translated(): void
    {
        $this->fakeOnvoConfirmation(['id' => 'pi_1', 'status' => 'requires_payment_method', 'lastPaymentError' => ['message' => 'Transaction declined due to test payment method used.']]);

        $result = $this->onvo()->charge($this->cardCharge());

        $this->assertSame(PaymentStatus::Rejected, $result->status);
        $this->assertSame('La tarjeta de prueba fue rechazada. Pruebe con otra tarjeta o pague con SINPE Móvil.', $result->failureReason);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function onvoKnownReasons(): array
    {
        $processing = 'No se pudo procesar la tarjeta. Pruebe de nuevo, con otra tarjeta o con SINPE Móvil.';

        return [
            'issuer declined' => [['code' => 'cards.invalid_card_info', 'message' => 'Raw', 'details' => ['card' => ['reason' => 'issuer_declined', 'declineCode' => '55', 'declineMessage' => 'Incorrect PIN']]], 'El banco emisor rechazó el pago. Pruebe con otra tarjeta o con otro método.'],
            'gateway declined' => [['details' => ['card' => ['reason' => 'gateway_declined']]], $processing],
            'onvo declined' => [['details' => ['card' => ['reason' => 'onvo_declined']]], $processing],
            'processor error' => [['details' => ['card' => ['reason' => 'processor_error']]], $processing],
            'invalid card information' => [['code' => 'cards.invalid_card_info', 'message' => 'There was an error with the card information provided.'], 'Los datos de la tarjeta no son válidos. Revíselos o pruebe con otra tarjeta.'],
        ];
    }

    /**
     * Every known reason of ONVO Pay is translated when the payment is created (the intent comes back refused), when it is asked for
     * again (the status query) and when the confirmation itself is refused with a 4xx.
     *
     * @param  array<string, mixed>  $error
     */
    #[DataProvider('onvoKnownReasons')]
    public function test_each_known_onvo_reason_is_translated(array $error, string $expected): void
    {
        $intent = ['id' => 'pi_1', 'status' => 'requires_payment_method', 'lastPaymentError' => $error];

        $this->fakeOnvoConfirmation($intent);
        $this->assertSame($expected, $this->onvo()->charge($this->cardCharge())->failureReason);

        Http::fake([self::ONVO.'/payment-intents/pi_1' => Http::response($intent)]);
        $this->assertSame($expected, $this->onvo()->status('pi_1', new PaymentId(self::PAYMENT))->failureReason);

        $this->fakeOnvoConfirmation($error, 400);
        $this->assertSame($expected, $this->onvo()->charge($this->cardCharge())->failureReason);
    }

    /** A SINPE Móvil refusal of ONVO Pay, whose text is not documented, is the generic sentence. */
    public function test_a_sinpe_refusal_is_the_generic_sentence(): void
    {
        Http::fake([
            self::ONVO.'/payment-methods' => Http::response(['id' => 'pm_s']),
            self::ONVO.'/payment-intents' => Http::response(['id' => 'pi_1']),
            self::ONVO.'/payment-intents/pi_1/confirm' => Http::response(['message' => ['Insufficient funds in the SINPE account']], 422),
        ]);

        $result = $this->onvo()->charge(new ChargeRequest(new PaymentId(self::PAYMENT), new ReservationId(self::RESERVATION), Money::of(2100), PaymentMethod::SinpeMovil, PaymentDetails::sinpeMovil('+50688888888', '01-1393-1919', '0', 'Ana Rojas', 'ana@barberhub.test')));

        $this->assertSame(PaymentStatus::Rejected, $result->status);
        $this->assertSame(DeclineReasons::GENERIC, $result->failureReason);
        $this->assertSame('Insufficient funds in the SINPE account', $this->declineEntry()['provider_message']);
    }

    /** A reason ONVO Pay does not document, or none at all, is the generic sentence and never the English text. */
    public function test_an_unknown_or_missing_onvo_reason_is_the_generic_sentence(): void
    {
        foreach ([['message' => 'Your bank said no, please call 800-555'], ['code' => 'weird_code', 'message' => 'Weird'], ['details' => ['card' => ['reason' => 'unknown']]], ['code' => null]] as $error) {
            $this->fakeOnvoConfirmation(['id' => 'pi_1', 'status' => 'requires_payment_method', 'lastPaymentError' => $error]);

            $this->assertSame(DeclineReasons::GENERIC, $this->onvo()->charge($this->cardCharge())->failureReason);
        }
    }

    /** A canceled intent is a failure with a Spanish sentence. */
    public function test_a_canceled_onvo_intent_has_a_spanish_reason(): void
    {
        Http::fake([self::ONVO.'/payment-intents/pi_1' => Http::response(['status' => 'canceled'])]);

        $status = $this->onvo()->status('pi_1', new PaymentId(self::PAYMENT));

        $this->assertSame(PaymentStatus::Failed, $status->status);
        $this->assertSame(self::CANCELED, $status->failureReason);
    }

    /** @return array<string, array{string, string}> */
    public static function paypalKnownIssues(): array
    {
        return [
            'instrument declined' => ['INSTRUMENT_DECLINED', 'PayPal rechazó el medio de pago. Pruebe con otra tarjeta o con otro método.'],
            'transaction refused' => ['TRANSACTION_REFUSED', 'PayPal rechazó la transacción. Pruebe con otro método de pago.'],
            'payer cannot pay' => ['PAYER_CANNOT_PAY', 'No es posible pagar con esta cuenta de PayPal. Pruebe con otro método de pago.'],
            'payer account restricted' => ['PAYER_ACCOUNT_RESTRICTED', 'La cuenta de PayPal tiene restricciones. Pruebe con otro método de pago.'],
            'payer account locked or closed' => ['PAYER_ACCOUNT_LOCKED_OR_CLOSED', 'La cuenta de PayPal está bloqueada o cerrada. Pruebe con otro método de pago.'],
        ];
    }

    /** Every refusal of a capture that PayPal documents and the adapter reads is translated; the English description only goes to the log. */
    #[DataProvider('paypalKnownIssues')]
    public function test_each_known_paypal_issue_is_translated(string $issue, string $expected): void
    {
        $this->fakePayPalCapture(Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed.', 'details' => [['issue' => $issue, 'description' => 'English description of the issue.']]], 422));

        $status = $this->paypal()->status('ORDER-1', new PaymentId(self::PAYMENT));

        $this->assertSame(PaymentStatus::Rejected, $status->status);
        $this->assertSame($expected, $status->failureReason);
        $this->assertSame('English description of the issue.', $this->declineEntry()['provider_message']);
        $this->assertSame($issue, $this->declineEntry()['provider_code']);
    }

    /** A capture the order reports as declined takes the generic sentence, and a voided order a Spanish one. */
    public function test_declined_capture_and_voided_order_of_paypal_are_in_spanish(): void
    {
        $declined = ['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'C-1', 'status' => 'DECLINED']]]]]];
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => $this->token(),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::sequence()->push($declined)->push(['id' => 'ORDER-1', 'status' => 'VOIDED']),
        ]);

        $rejected = $this->paypal()->status('ORDER-1', new PaymentId(self::PAYMENT));
        $voided = $this->paypal()->status('ORDER-1', new PaymentId(self::PAYMENT));

        $this->assertSame(PaymentStatus::Rejected, $rejected->status);
        $this->assertSame(DeclineReasons::GENERIC, $rejected->failureReason);
        $this->assertSame('DECLINED', $this->declineEntry()['provider_code']);
        $this->assertSame(PaymentStatus::Failed, $voided->status);
        $this->assertSame(self::CANCELED, $voided->failureReason);
    }

    /** A refund ONVO Pay refuses is a Spanish sentence; the English message of the provider only reaches the log. */
    public function test_a_refused_onvo_refund_is_in_spanish(): void
    {
        Http::fake([self::ONVO.'/refunds' => Http::response(['message' => 'Already refunded'], 400)]);

        $refused = $this->onvo()->refund('pi_1', Money::of(5000), new PaymentId(self::PAYMENT));

        $this->assertFalse($refused->accepted);
        $this->assertSame(DeclineReasons::REFUND_REFUSED, $refused->failureReason);
        $this->assertSame('Already refunded', $this->declineEntry()['provider_message']);
    }

    /** A payment the provider does not accept to create keeps a Spanish reason, not an internal code, in both gateways. */
    public function test_a_request_the_provider_does_not_accept_carries_a_spanish_reason(): void
    {
        Http::fake([self::ONVO.'/payment-intents' => Http::response(['message' => 'Bad request'], 400)]);

        try {
            $this->onvo()->charge($this->cardCharge());
            $this->fail('The rejection was not reported.');
        } catch (GatewayRequestRejectedException $exception) {
            $this->assertSame(DeclineReasons::REQUEST_REJECTED, $exception->customerReason);
        }
    }

    /** The log keeps the original text and code with the payment and the request, and neither a card number nor the key. */
    public function test_the_log_keeps_the_original_without_sensitive_data(): void
    {
        $this->fakeOnvoConfirmation(['id' => 'pi_1', 'status' => 'requires_payment_method', 'lastPaymentError' => [
            'code' => 'declined',
            'message' => 'Card 4000 0000 0000 0002 refused with key '.self::SECRET.' by the bank',
        ]]);

        $result = $this->onvo()->charge($this->cardCharge());

        $this->assertSame(DeclineReasons::GENERIC, $result->failureReason);
        $entry = $this->declineEntry();
        $this->assertSame('onvo', $entry['gateway']);
        $this->assertSame('declined', $entry['provider_code']);
        $this->assertSame(self::PAYMENT, $entry['payment_id']);
        $this->assertSame(self::REQUEST_ID, $entry['request_id']);
        $this->assertStringContainsString('refused with key', $entry['provider_message']);
        $this->assertStringNotContainsString('4000 0000 0000 0002', $entry['provider_message']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($this->entries, JSON_THROW_ON_ERROR));
    }

    /**
     * The last decline entry of the log.
     *
     * @return array<string, mixed>
     */
    private function declineEntry(): array
    {
        $entries = array_values(array_filter($this->entries, fn (array $entry): bool => $entry[0] === 'info' && array_key_exists('provider_message', $entry[2])));
        $last = $entries[count($entries) - 1] ?? $this->fail('The decline was not logged.');

        return $last[2];
    }

    /** @param  array<string, mixed>  $confirmation */
    private function fakeOnvoConfirmation(array $confirmation, int $status = 200): void
    {
        Http::fake([
            self::ONVO.'/payment-intents' => Http::response(['id' => 'pi_1', 'status' => 'requires_payment_method']),
            self::ONVO.'/payment-intents/pi_1/confirm' => Http::response($confirmation, $status),
        ]);
    }

    private function fakePayPalCapture(mixed $capture): void
    {
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => $this->token(),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => $capture,
        ]);
    }

    private function token(): mixed
    {
        return Http::response(['access_token' => 'A21AAL_test_access_token', 'token_type' => 'Bearer', 'expires_in' => 32400]);
    }

    private function onvo(): OnvoGateway
    {
        return new OnvoGateway($this->app->make(Factory::class), self::SECRET, self::ONVO, '', '+506 70196686');
    }

    private function paypal(): PayPalGateway
    {
        return new PayPalGateway($this->app->make(Factory::class), $this->app->make(CacheRepository::class), 'client-id', 'client-secret', self::PAYPAL, 'https://app.test/retorno', 'https://app.test/cancelado', 500);
    }

    private function cardCharge(): ChargeRequest
    {
        return new ChargeRequest(new PaymentId(self::PAYMENT), new ReservationId(self::RESERVATION), Money::of(5000), PaymentMethod::Card, PaymentDetails::card('pm_1'));
    }
}
