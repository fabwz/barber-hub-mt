<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Payments\PaymentNoticeScenario;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/**
 * The notices of PayPal (RF-22, RNF-07, PA-03 to PA-05): verified by PayPal's own service, applied once, and only as a trigger:
 * the state comes from asking PayPal. The verification service and the gateway are simulated; no request leaves the process.
 */
final class PayPalNoticeTest extends TestCase
{
    use PaymentNoticeScenario;
    use RefreshDatabaseAsOwner;

    private const string URL = '/api/v1/pagos/avisos/paypal';

    private const string WEBHOOK_ID = '0NH55953DH663215D';

    /** What the verification service of PayPal answers; the requests it received. */
    private string|int $verification = 'SUCCESS';

    /** @var list<Request> */
    private array $verifications = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario(PaymentProvider::Paypal);
        config([
            'payments.paypal.webhook_id' => self::WEBHOOK_ID,
            'payments.paypal.client_id' => 'client-id',
            'payments.paypal.client_secret' => 'client-secret',
            'payments.paypal.base_url' => 'https://api-m.sandbox.paypal.com',
        ]);
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'sandbox-token', 'expires_in' => 3600]),
            '*/v1/notifications/verify-webhook-signature' => function (Request $request) {
                $this->verifications[] = $request;

                return match (true) {
                    $this->verification === 'DOWN' => throw new ConnectionException('timeout'),
                    is_int($this->verification) => Http::response([], $this->verification),
                    default => Http::response(['verification_status' => $this->verification]),
                };
            },
        ]);
    }

    /** An order approved by the customer makes the system ask PayPal, which settles the payment and confirms the reservation (RF-22, PA-06). */
    public function test_an_approved_order_notice_confirms_the_reservation(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->notice($this->orderApproved('WH-1', $payment['reference']))->assertOk()->assertExactJson(['recibido' => true]);

        $this->assertSame(['payment' => 'approved', 'reservation' => 'confirmed', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertSame([$payment['reference']], $this->gateway->statusChecks);
    }

    /** A completed capture is read through the order it belongs to. */
    public function test_a_completed_capture_notice_confirms_the_reservation(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->notice($this->capture('WH-1', 'PAYMENT.CAPTURE.COMPLETED', $payment['reference']))->assertOk();

        $this->assertSame('confirmed', $this->statesOf($payment['payment'], $payment['reservation'])['reservation']);
    }

    /** A declined capture leaves the reservation temporary and records the failure. */
    public function test_a_declined_capture_notice_leaves_the_reservation_temporary(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->rejects('INSTRUMENT_DECLINED');

        $this->notice($this->capture('WH-1', 'PAYMENT.CAPTURE.DECLINED', $payment['reference']))->assertOk();

        $this->assertSame(['payment' => 'rejected', 'reservation' => 'temporary', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
    }

    /** PayPal verifies the notice with the transmission headers, the webhook identifier and the event exactly as it came. */
    public function test_the_verification_carries_the_transmission_the_webhook_and_the_raw_event(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = '{ "id" : "WH-1","event_type":"CHECKOUT.ORDER.APPROVED", "resource":{"id":"'.$payment['reference'].'"}, "link": "https:\/\/x.test\/a" }';

        $this->notice($body)->assertOk();

        $this->assertCount(1, $this->verifications);
        $sent = $this->verifications[0];
        $this->assertSame('Bearer sandbox-token', $sent->header('Authorization')[0]);
        $this->assertStringContainsString('"webhook_event":'.$body.'}', $sent->body());
        $decoded = json_decode($sent->body(), true);
        $this->assertSame(self::WEBHOOK_ID, $decoded['webhook_id']);
        $this->assertSame('SHA256withRSA', $decoded['auth_algo']);
        $this->assertSame('https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1', $decoded['cert_url']);
        $this->assertSame('transmission-1', $decoded['transmission_id']);
        $this->assertSame('signature-1', $decoded['transmission_sig']);
        $this->assertSame('2030-03-01T14:00:00Z', $decoded['transmission_time']);
    }

    /** A verification that is not SUCCESS, or an answer that is not a verdict, refuses the notice and changes nothing (PA-04). */
    public function test_a_notice_that_paypal_does_not_verify_changes_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->orderApproved('WH-1', $payment['reference']);

        foreach (['FAILURE', 'PENDING', '', 400, 422] as $verdict) {
            $this->verification = $verdict;
            $this->notice($body)->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
        }

        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
        $this->assertSame(0, $this->recordedNotices());
        $this->assertSame([], $this->gateway->statusChecks);
    }

    /** Without the transmission headers, or with a body that is not an event, PayPal is not even asked. */
    public function test_a_notice_without_transmission_or_without_an_event_is_refused(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->orderApproved('WH-1', $payment['reference']);

        foreach (['paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time'] as $missing) {
            $this->notice($body, without: $missing)->assertUnauthorized();
        }

        $this->notice('[1,2]')->assertUnauthorized();
        $this->notice('not json')->assertUnauthorized();

        $this->assertSame([], $this->verifications);
        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
    }

    /** Without the webhook identifier nothing is accepted: 503, and PayPal is not asked. */
    public function test_without_the_webhook_id_the_endpoint_refuses_everything(): void
    {
        config(['payments.paypal.webhook_id' => '']);
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->notice($this->orderApproved('WH-1', $payment['reference']))->assertStatus(503);

        $this->assertSame([], $this->verifications);
        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
    }

    /** If PayPal cannot verify the notice, the answer is 503 and nothing is recorded, so PayPal sends it again. */
    public function test_a_verification_service_that_is_down_answers_503(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->orderApproved('WH-1', $payment['reference']);

        foreach (['DOWN', 500, 401] as $failure) {
            $this->verification = $failure;
            $this->notice($body)->assertStatus(503);
        }

        $this->assertSame(0, $this->recordedNotices());

        $this->verification = 'SUCCESS';
        $this->notice($body)->assertOk();
        $this->assertSame('confirmed', $this->statesOf($payment['payment'], $payment['reservation'])['reservation']);
    }

    /** The same event ten times leaves one record, one payment and one confirmed reservation (RNF-07, PA-05). */
    public function test_the_same_event_ten_times_is_applied_once(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();
        $body = $this->orderApproved('WH-same', $payment['reference']);

        foreach (range(1, 10) as $ignored) {
            $this->notice($body)->assertOk();
        }

        $this->assertSame(1, $this->recordedNotices());
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(['payment' => 'approved', 'reservation' => 'confirmed', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertCount(1, $this->gateway->statusChecks);
    }

    /** With PayPal not answering when asked about the order, the answer is 503, nothing is recorded and the retry is processed (RNF-11). */
    public function test_a_gateway_that_does_not_answer_is_retried_later(): void
    {
        $payment = $this->pendingPayment();
        $body = $this->orderApproved('WH-1', $payment['reference']);

        $this->gateway->isUnavailable();
        $this->notice($body)->assertStatus(503);
        $this->assertSame(0, $this->recordedNotices());

        $this->gateway->approves();
        $this->notice($body)->assertOk();
        $this->assertSame('confirmed', $this->statesOf($payment['payment'], $payment['reservation'])['reservation']);
        $this->assertSame(1, $this->recordedNotices());
    }

    /** A notice of an order the system does not know, or of an event it does not use, is acknowledged and changes nothing. */
    public function test_an_unknown_order_and_other_events_are_acknowledged(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->notice($this->orderApproved('WH-1', 'UNKNOWN-ORDER'))->assertOk();
        $this->notice($this->capture('WH-2', 'PAYMENT.CAPTURE.REFUNDED', $payment['reference']))->assertOk();
        $this->notice('{"id":"WH-3","event_type":"CHECKOUT.ORDER.APPROVED"}')->assertOk();

        $this->assertSame('pending', $this->statesOf($payment['payment'], $payment['reservation'])['payment']);
        $this->assertSame(0, $this->recordedNotices());
        $this->assertSame([], $this->gateway->statusChecks);
    }

    /** A payment approved after its reservation expired stays approved and marked to give back (PA-07). */
    public function test_an_approved_payment_with_an_expired_reservation_is_marked_to_give_back(): void
    {
        $payment = $this->pendingPayment();
        $this->clock->moveTo($this->clock->now()->modify('+10 minutes'));
        $this->gateway->approves();

        $this->notice($this->capture('WH-1', 'PAYMENT.CAPTURE.COMPLETED', $payment['reference']))->assertOk();

        $states = $this->statesOf($payment['payment'], $payment['reservation']);
        $this->assertSame('approved', $states['payment']);
        $this->assertTrue($states['refund_pending']);
        $this->assertNotSame('confirmed', $states['reservation']);
    }

    /** The return of the browser from PayPal changes no state (RF-21, PA-03). */
    public function test_the_return_of_the_browser_confirms_nothing(): void
    {
        $payment = $this->pendingPayment();
        $this->gateway->approves();

        $this->getJson('/api/v1/pagos/retorno?token='.$payment['reference'].'&PayerID=PAYER1')->assertOk();

        $this->assertSame(['payment' => 'pending', 'reservation' => 'temporary', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertSame([], $this->verifications);
        $this->assertSame([], $this->gateway->statusChecks);
    }

    /** The JSON of `CHECKOUT.ORDER.APPROVED`: the resource is the order. */
    private function orderApproved(string $id, string $order): string
    {
        return (string) json_encode(['id' => $id, 'event_version' => '1.0', 'resource_type' => 'checkout-order', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => $order, 'status' => 'APPROVED']]);
    }

    /** The JSON of an event of a capture: the order is in the related identifiers. */
    private function capture(string $id, string $type, string $order): string
    {
        return (string) json_encode([
            'id' => $id, 'event_version' => '1.0', 'resource_type' => 'capture', 'event_type' => $type,
            'resource' => ['id' => 'CAPTURE-1', 'status' => 'COMPLETED', 'supplementary_data' => ['related_ids' => ['order_id' => $order]]],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send a notice as PayPal does: raw JSON with the transmission headers.
     *
     * @return TestResponse<Response>
     */
    private function notice(string $body, ?string $without = null)
    {
        $headers = [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'paypal-transmission-id' => 'transmission-1',
            'paypal-transmission-sig' => 'signature-1',
            'paypal-transmission-time' => '2030-03-01T14:00:00Z',
        ];
        unset($headers[$without]);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', self::URL, [], [], [], $server, $body);
    }
}
