<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Payments\PaymentNoticeScenario;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/**
 * The notices of ONVO Pay (RF-22, RNF-07, PA-03 to PA-05, BO-02): verified by signature, applied once, and only as a trigger: the
 * state comes from asking the provider. The gateway is simulated and no request leaves the process.
 */
final class OnvoNoticeTest extends TestCase
{
    use PaymentNoticeScenario;
    use RefreshDatabaseAsOwner;

    private const string SECRET = 'onvo-test-signing-secret';

    private const string URL = '/api/v1/pagos/avisos/onvo';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario(PaymentProvider::Onvo);
        config(['payments.onvo.webhook_secret' => self::SECRET]);
    }

    /** A notice with a wrong signature is refused with 401 and nothing changes (PA-04, RF-22). */
    public function test_a_notice_with_an_invalid_signature_changes_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->event('evt_1', 'payment_intent.succeeded', $payment['reference']);

        $this->notice($body, 't='.$this->now().',v1='.str_repeat('0', 64))->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
        $this->notice($body, $this->signature($body, 'onvo-another-secret'))->assertUnauthorized();

        $this->assertSame(['payment' => 'pending', 'reservation' => 'temporary', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertSame(0, $this->recordedNotices());
        $this->assertSame([], $this->gateway->statusChecks);
    }

    /** A notice with no signature, a malformed one or one signed for another body is refused too. */
    public function test_a_notice_without_a_signature_or_with_a_tampered_body_is_refused(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->event('evt_1', 'payment_intent.succeeded', $payment['reference']);

        $this->notice($body, null)->assertUnauthorized();
        $this->notice($body, 'garbage')->assertUnauthorized();
        $this->notice($body, $this->signature($body.' '))->assertUnauthorized();

        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
        $this->assertSame(0, $this->recordedNotices());
    }

    /** A signature of another moment is refused: a captured notice cannot be replayed later. */
    public function test_a_stale_signature_is_refused(): void
    {
        $payment = $this->pendingPayment();
        $body = $this->event('evt_1', 'payment_intent.succeeded', $payment['reference']);

        $this->notice($body, $this->signature($body, self::SECRET, $this->clock->now()->modify('-6 minutes')->getTimestamp()))->assertUnauthorized();
        $this->notice($body, $this->signature($body, self::SECRET, $this->clock->now()->modify('+6 minutes')->getTimestamp()))->assertUnauthorized();
    }

    /** During a rotation of the secret ONVO sends two signatures: either one is enough. */
    public function test_either_signature_of_a_rotation_is_accepted(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->event('evt_1', 'payment_intent.succeeded', $payment['reference']);
        $old = $this->signature($body, 'onvo-the-old-secret');
        $new = $this->signature($body, self::SECRET);

        $this->notice($body, $old.','.substr($new, strpos($new, ',') + 1))->assertOk();
        $this->assertSame('confirmed', $this->statesOf($payment['payment'], $payment['reservation'])['reservation']);
    }

    /** Without the secret configured the endpoint answers 503 and never accepts, not even a signature made with an empty secret. */
    public function test_without_the_secret_the_endpoint_refuses_everything(): void
    {
        config(['payments.onvo.webhook_secret' => '']);
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->event('evt_1', 'payment_intent.succeeded', $payment['reference']);

        $this->notice($body, $this->signature($body, ''))->assertStatus(503)->assertHeader('Content-Type', 'application/problem+json');
        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
        $this->assertSame(0, $this->recordedNotices());
    }

    /** An approved payment confirms the reservation (RF-22, PA-06). */
    public function test_an_approved_notice_confirms_the_reservation(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $payment['reference']))->assertOk()->assertExactJson(['recibido' => true]);

        $this->assertSame(['payment' => 'approved', 'reservation' => 'confirmed', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertSame(1, $this->recordedNotices());
    }

    /** A failed payment is recorded and leaves the reservation temporary, so the customer can try again (RF-21, RF-22). */
    public function test_a_failed_notice_leaves_the_reservation_temporary(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->rejects('Fondos insuficientes');

        $this->notice($this->event('evt_1', 'payment_intent.failed', $payment['reference']))->assertOk();

        $this->assertSame(['payment' => 'rejected', 'reservation' => 'temporary', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
    }

    /** The notice is only a trigger: a notice that says "succeeded" while ONVO says otherwise confirms nothing. */
    public function test_a_notice_that_the_provider_does_not_back_confirms_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->leavesPending();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $payment['reference']))->assertOk();

        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
        $this->assertSame('temporary', $this->statesOf($payment['payment'], $payment['reservation'])['reservation']);
    }

    /** The same event ten times leaves one record, one payment, one confirmed reservation and one question to the provider (RNF-07, PA-05). */
    public function test_the_same_event_ten_times_is_applied_once(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->event('evt_same', 'payment_intent.succeeded', $payment['reference']);

        foreach (range(1, 10) as $ignored) {
            $this->notice($body)->assertOk()->assertExactJson(['recibido' => true]);
        }

        $this->assertSame(1, $this->recordedNotices());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(['payment' => 'approved', 'reservation' => 'confirmed', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertCount(1, $this->gateway->statusChecks);
        $this->assertSame(1, DB::table('reservation_status_history')->where('reservation_id', $payment['reservation'])->where('to_status', 'confirmed')->count());
    }

    /** A second event of the same payment, with another identifier, finds it settled and changes nothing more. */
    public function test_another_event_of_a_settled_payment_changes_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $payment['reference']))->assertOk();
        $this->notice($this->event('evt_2', 'payment_intent.succeeded', $payment['reference']))->assertOk();
        $this->notice($this->event('evt_3', 'payment_intent.failed', $payment['reference']))->assertOk();

        $this->assertSame(['payment' => 'approved', 'reservation' => 'confirmed', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertSame(3, $this->recordedNotices());
        $this->assertCount(1, $this->gateway->statusChecks);
    }

    /** A notice of a payment the system does not know, or about something else, is acknowledged and changes nothing. */
    public function test_an_unknown_payment_and_other_events_are_acknowledged(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', 'unknown_intent'))->assertOk()->assertExactJson(['recibido' => true]);
        $this->notice($this->event('evt_2', 'refund.succeeded', $payment['reference']))->assertOk();
        $this->notice($this->event('evt_3', 'webhook_endpoint.test', $payment['reference']))->assertOk();
        $this->notice($this->event('evt_4', 'payment_intent.succeeded', $payment['reference'], 'live'))->assertOk();
        $this->notice('{"not":"an event"}')->assertOk();

        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
        $this->assertSame(0, $this->recordedNotices());
        $this->assertSame([], $this->gateway->statusChecks);
    }

    /** With the provider down the answer is 503 and the event is not recorded; the retry is processed (RNF-11). */
    public function test_a_provider_that_does_not_answer_is_retried_later(): void
    {
        $payment = $this->pendingPayment();
        $body = $this->event('evt_1', 'payment_intent.succeeded', $payment['reference']);

        $this->gateway->isUnavailable();
        $this->notice($body)->assertStatus(503)->assertHeader('Content-Type', 'application/problem+json');
        $this->assertSame(0, $this->recordedNotices());
        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);

        $this->gateway->approves();
        $this->notice($body)->assertOk();

        $this->assertSame(1, $this->recordedNotices());
        $this->assertSame(['payment' => 'approved', 'reservation' => 'confirmed', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
    }

    /** The same with the real adapter: a lost connection to ONVO answers 503 and the rest of the system keeps working (RNF-11). */
    public function test_a_connection_that_fails_with_the_real_adapter_answers_503_and_the_system_goes_on(): void
    {
        $payment = $this->pendingPayment();
        config(['payments.gateways' => ['onvo' => OnvoGateway::class], 'payments.onvo.secret_key' => 'onvo_test_secret_key_1']);
        $calls = 0;
        // ONVO does not answer the first time and answers the second.
        Http::fake(['*' => function () use (&$calls, $payment) {
            if (++$calls === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['id' => $payment['reference'], 'status' => 'succeeded']);
        }]);

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $payment['reference']))->assertStatus(503);
        $this->assertSame(0, $this->recordedNotices());

        $this->getJson('/api/v1/pagos/retorno')->assertOk();
        $this->notice($this->event('evt_2', 'payment_intent.succeeded', 'unknown_intent'))->assertOk();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $payment['reference']))->assertOk();
        $this->assertSame('confirmed', $this->statesOf($payment['payment'], $payment['reservation'])['reservation']);
    }

    /** A payment approved after its reservation expired stays approved and marked to give back (PA-07). */
    public function test_an_approved_payment_with_an_expired_reservation_is_marked_to_give_back(): void
    {
        $payment = $this->pendingPayment();
        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 08:10:00', new DateTimeZone('America/Costa_Rica')));
        $this->gateway->approves();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $payment['reference']))->assertOk();

        $states = $this->statesOf($payment['payment'], $payment['reservation']);
        $this->assertSame('approved', $states['payment']);
        $this->assertTrue($states['refund_pending']);
        $this->assertNotSame('confirmed', $states['reservation']);
    }

    /** The notice of a payment of one barbershop never touches the data of another (MT-01). */
    public function test_a_notice_of_one_barbershop_does_not_touch_another(): void
    {
        $mine = $this->pendingPayment();
        $theirs = $this->paymentOfFadeLab();
        $this->gateway->approves();

        $this->notice($this->event('evt_1', 'payment_intent.succeeded', $theirs['reference']))->assertOk();

        $this->assertSame(['payment' => 'pending', 'reservation' => 'temporary', 'refund_pending' => false], $this->statesOf($mine['payment'], $mine['reservation']));
        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame('approved', DB::table('payments')->where('id', $theirs['payment'])->value('status'));
        $this->assertSame('confirmed', DB::table('reservations')->where('id', $theirs['reservation'])->value('status'));
        $this->assertSame([$theirs['reference']], $this->gateway->statusChecks);
    }

    /** The return of the browser changes no state, with or without success parameters: only the notice and the questions to the provider do (RF-21, PA-03). */
    public function test_the_return_of_the_browser_confirms_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->getJson('/api/v1/pagos/retorno?status=succeeded&payment_intent='.$payment['reference'].'&redirect_status=succeeded')->assertOk();

        $this->assertSame(['payment' => 'pending', 'reservation' => 'temporary', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertSame([], $this->gateway->statusChecks);
        $this->assertSame(0, $this->recordedNotices());
    }

    /** The body must be JSON and small; anything else is refused before it is read. */
    public function test_the_body_must_be_json_and_small(): void
    {
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'x')->assertStatus(400);
        $this->call('POST', self::URL, [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 70000))->assertStatus(400);
    }

    /** The endpoint is limited per address, so nobody floods the verification. */
    public function test_the_endpoint_is_rate_limited(): void
    {
        foreach (range(1, 120) as $ignored) {
            $this->notice('{}', null)->assertUnauthorized();
        }

        $this->notice('{}', null)->assertStatus(429)->assertHeader('Retry-After');
    }

    /** The log has the provider, the event, the result and the correlation, and no secret, signature or body. */
    public function test_the_log_keeps_no_secret_signature_or_body(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = json_encode([$event->message, $event->context]);
        });
        $body = $this->event('evt_secret_check', 'payment_intent.succeeded', $payment['reference']);
        $signature = $this->signature($body);

        $this->notice($body, $signature)->assertOk();
        $this->notice($body, 't=1,v1='.str_repeat('a', 64))->assertUnauthorized();

        $text = implode("\n", $logged);
        $this->assertStringContainsString('evt_secret_check', $text);
        $this->assertStringContainsString('"gateway":"onvo"', $text);
        $this->assertStringNotContainsString(self::SECRET, $text);
        $this->assertStringNotContainsString(substr($signature, strpos($signature, 'v1=') + 3), $text);
        $this->assertStringNotContainsString($payment['reference'], $text);
        $this->assertStringNotContainsString('"data"', $text);
    }

    /**
     * A payment of Fade Lab with a temporary reservation, written straight into the tables, for the isolation test.
     *
     * @return array{reservation: string, payment: string, reference: string}
     */
    private function paymentOfFadeLab(): array
    {
        [, $membership] = $this->insertCustomer($this->fadeLab, 'Luis Mora');
        $reservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $membership, '2030-03-05 10:00:00-06:00', 'temporary', 30);
        $payment = (string) Str::uuid7();
        $this->scopeQueriesTo($this->fadeLab);
        DB::table('reservations')->where('id', $reservation)->update(['expires_at' => '2030-03-01 08:05:00-06:00']);
        DB::table('payments')->insert([
            'id' => $payment, 'barbershop_id' => $this->fadeLab, 'reservation_id' => $reservation, 'provider' => 'onvo', 'method' => 'card',
            'status' => 'pending', 'amount' => 1500, 'provider_payment_id' => 'fade_lab_intent',
        ]);

        return ['reservation' => $reservation, 'payment' => $payment, 'reference' => 'fade_lab_intent'];
    }

    /** The JSON of an event of ONVO Pay for a payment intent. */
    private function event(string $id, string $type, string $intent, string $mode = 'test'): string
    {
        return (string) json_encode([
            'id' => $id, 'object' => 'event', 'createdAt' => '2030-03-01T14:00:00Z', 'mode' => $mode, 'accountId' => 'acc_1', 'type' => $type,
            'data' => ['object' => ['object' => 'payment_intent', 'id' => $intent, 'status' => 'succeeded']],
        ]);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /** The header `X-Onvo-Signature` of a body: `t=<seconds>,v1=<HMAC-SHA256 of "t.body" in hex>`. */
    private function signature(string $body, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= $this->now();

        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
    }

    /**
     * Send a notice as ONVO Pay does: raw JSON with the signature header; by default correctly signed.
     *
     * @return TestResponse<Response>
     */
    private function notice(string $body, ?string $signature = '', ?string $url = null)
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_ONVO_SIGNATURE'] = $signature === '' ? $this->signature($body) : $signature;
        }

        return $this->call('POST', $url ?? self::URL, [], [], [], $server, $body);
    }
}
