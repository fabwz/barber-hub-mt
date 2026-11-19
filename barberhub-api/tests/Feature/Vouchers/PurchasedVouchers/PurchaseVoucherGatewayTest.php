<?php

declare(strict_types=1);

namespace Tests\Feature\Vouchers\PurchasedVouchers;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Payments\PaymentNoticeScenario;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\TestCase;

/**
 * The gateway a voucher is bought through (RF-20, RF-27, PA-02, PA-03, BO-02): with PayPal the purchase answers what a reservation
 * answers and the voucher is activated only by the confirmed payment, and with a barbershop that does not charge the purchase
 * still charges the full price. The gateways are simulated and no request leaves the process (PA-09).
 */
final class PurchaseVoucherGatewayTest extends TestCase
{
    use PaymentNoticeScenario;
    use RefreshDatabaseAsOwner;
    use SeedsVouchers;

    private const string PAYPAL_NOTICES = '/api/v1/pagos/avisos/paypal';

    private string $voucher;

    /** Pura Navaja sells "Bono 5 cortes"; the verification service of PayPal confirms every notice it is sent. */
    private function sellVoucher(PaymentProvider $provider): void
    {
        $this->setUpScenario($provider);
        $this->voucher = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
        $this->scopeQueriesTo($this->puraNavaja);

        if ($provider === PaymentProvider::Paypal) {
            config([
                'payments.paypal.webhook_id' => '0NH55953DH663215D',
                'payments.paypal.client_id' => 'client-id',
                'payments.paypal.client_secret' => 'client-secret',
                'payments.paypal.base_url' => 'https://api-m.sandbox.paypal.com',
            ]);
            Http::fake([
                '*/v1/oauth2/token' => Http::response(['access_token' => 'sandbox-token', 'expires_in' => 3600]),
                '*/v1/notifications/verify-webhook-signature' => fn (Request $request) => Http::response(['verification_status' => 'SUCCESS']),
            ]);
        }
    }

    /** With PayPal the purchase answers the same next action as a reservation (a redirection to PayPal) and charges the full price through PayPal (RF-20, RF-27). */
    public function test_buying_with_paypal_answers_the_same_next_action_as_a_reservation(): void
    {
        $this->sellVoucher(PaymentProvider::Paypal);
        $this->gateway->leavesPending();
        $this->actAs($this->ana);

        $reservation = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        $forReservation = $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$reservation}/pagos", ['metodo' => 'tarjeta', 'token' => 'pm_test_1'])
            ->assertCreated()
            ->json('data.accion_siguiente');
        $forVoucher = $this->buy()->assertCreated()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.monto', 30000)->json('data.accion_siguiente');

        $this->assertSame('redireccion', $forReservation['tipo']);
        $this->assertSame($forReservation['tipo'], $forVoucher['tipo']);
        $this->assertSame(array_keys($forReservation), array_keys($forVoucher));
        $this->assertStringStartsWith('https://', $forVoucher['url']);
        $this->assertSame(PaymentProvider::Paypal->value, $this->theVoucherPayment()->provider);
    }

    /** The voucher bought with PayPal waits: neither the charge, nor the return of the browser, nor an unrelated notice activates it; only the verified notice of the confirmed payment does (BO-02, PA-03). */
    public function test_a_voucher_bought_with_paypal_is_activated_only_when_the_payment_is_confirmed(): void
    {
        $this->sellVoucher(PaymentProvider::Paypal);
        $this->gateway->leavesPending();
        $this->actAs($this->ana);
        $paymentId = $this->buy()->assertCreated()->json('data.id');
        $reference = (string) $this->theVoucherPayment()->provider_payment_id;

        $this->assertSame('pending:0', $this->stateOfTheVoucher());

        $this->getJson('/api/v1/pagos/retorno?token='.$reference.'&PayerID=PAYER1')->assertOk();
        $this->assertSame('pending:0', $this->stateOfTheVoucher());
        $this->assertSame([], $this->gateway->statusChecks);

        $this->gateway->approves();
        $this->paypalNotice('WH-1', $reference)->assertOk();

        $voucher = $this->theVoucher();
        $this->assertSame(['active', 5], [$voucher->status, $voucher->remaining_sessions]);
        $this->assertNotNull($voucher->expires_at);
        $this->assertSame('approved', (string) DB::table('payments')->where('id', $paymentId)->value('status'));
        $this->assertSame(1, DB::table('voucher_movements')->where('purchased_voucher_id', $voucher->id)->count());

        // The same notice again changes nothing: one purchase entry, the same balance.
        $this->paypalNotice('WH-1', $reference)->assertOk();
        $this->assertSame(5, $this->theVoucher()->remaining_sessions);
        $this->assertSame(1, DB::table('voucher_movements')->where('purchased_voucher_id', $voucher->id)->count());
    }

    /** A PayPal payment the provider rejects leaves the voucher unpaid and without sessions. */
    public function test_a_rejected_paypal_payment_leaves_the_voucher_inactive(): void
    {
        $this->sellVoucher(PaymentProvider::Paypal);
        $this->gateway->rejects('INSTRUMENT_DECLINED');
        $this->actAs($this->ana);

        $this->buy()->assertCreated()->assertJsonPath('data.estado', 'rechazado');

        $this->assertSame(['pending', 0, null], [$this->theVoucher()->status, $this->theVoucher()->remaining_sessions, $this->theVoucher()->activated_at]);
    }

    /**
     * What the purchase does today with a barbershop that does not charge (`sin_pago`, no gateway): the policy of the barbershop is
     * not read, so it charges the full price through the default gateway of the system (PAYMENTS_DEFAULT_GATEWAY, ONVO Pay) and
     * the voucher waits for the confirmed payment like any other. The list says `pasarela` null for the same barbershop.
     */
    public function test_buying_in_a_barbershop_that_does_not_charge_still_charges_the_full_price_through_the_default_gateway(): void
    {
        $this->sellVoucher(PaymentProvider::Onvo);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'none', 'deposit_percentage' => null, 'payment_gateway' => null]);
        $this->gateway->leavesPending();
        $this->actAs($this->ana);

        $this->getJson("/api/v1/barberias/{$this->puraNavaja}/bonos")->assertOk()->assertJsonPath('meta.pasarela', null);
        $this->buy()->assertCreated()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.monto', 30000)->assertJsonPath('data.accion_siguiente.tipo', 'redireccion');

        $this->assertCount(1, $this->gateway->charges);
        $this->assertSame(30000, $this->gateway->charges[0]->amount->colones);
        $this->assertSame(PaymentProvider::Onvo->value, $this->theVoucherPayment()->provider);
        $this->assertSame('pending:0', $this->stateOfTheVoucher());
    }

    /**
     * Buy the voucher with the card, as the person of the session.
     *
     * @return TestResponse<Response>
     */
    private function buy()
    {
        return $this->postJson("/api/v1/barberias/{$this->puraNavaja}/bonos/{$this->voucher}/compras", ['metodo' => 'tarjeta', 'token' => 'pm_test_1']);
    }

    /** The only voucher of Pura Navaja. */
    private function theVoucher(): stdClass
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return DB::table('purchased_vouchers')->sole();
    }

    /**
     * The status and the sessions of the only voucher of Pura Navaja.
     *
     * @return non-empty-string
     *
     * @phpstan-impure
     */
    private function stateOfTheVoucher(): string
    {
        $voucher = $this->theVoucher();

        return $voucher->status.":{$voucher->remaining_sessions}";
    }

    /** The payment of the voucher of Pura Navaja. */
    private function theVoucherPayment(): stdClass
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return DB::table('payments')->whereNotNull('purchased_voucher_id')->sole();
    }

    /**
     * Send the notice of an approved PayPal order as PayPal does: raw JSON with the transmission headers.
     *
     * @return TestResponse<Response>
     */
    private function paypalNotice(string $id, string $order)
    {
        $body = (string) json_encode(['id' => $id, 'event_version' => '1.0', 'resource_type' => 'checkout-order', 'event_type' => 'CHECKOUT.ORDER.APPROVED', 'resource' => ['id' => $order, 'status' => 'APPROVED']]);
        $server = [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'transmission-1', 'HTTP_PAYPAL_TRANSMISSION_SIG' => 'signature-1', 'HTTP_PAYPAL_TRANSMISSION_TIME' => '2030-03-01T14:00:00Z',
        ];

        return $this->call('POST', self::PAYPAL_NOTICES, [], [], [], $server, $body);
    }
}
