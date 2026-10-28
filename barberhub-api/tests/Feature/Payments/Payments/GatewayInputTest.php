<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Src\Payments\Payments\Infrastructure\Gateways\OnvoGateway;
use Src\Payments\Payments\Infrastructure\Gateways\PayPalGateway;
use Src\Shared\Domain\Contracts\Clock;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * What the endpoint of payments asks of the customer depends on the gateway of the barbershop, not on the code of the endpoint:
 * PayPal needs only the method, ONVO Pay needs its fields. Both run the same endpoint with the same shape of response, over the
 * real adapters with the answers of the providers simulated (PA-09). Pura Navaja charges a 30 % deposit on Degradado: ₡2.100.
 */
final class GatewayInputTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const string ONVO = 'https://api.onvopay.com/v1';

    private const string PAYPAL = 'https://api-m.sandbox.paypal.com';

    private string $ana;

    /** Configure both adapters with test credentials and forbid any request that is not faked. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::flush();
        $this->seedBookableBarbershops();
        $this->app->instance(Clock::class, new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'))));
        config([
            'payments.gateways' => ['onvo' => OnvoGateway::class, 'paypal' => PayPalGateway::class],
            'payments.onvo.secret_key' => 'onvo_test_secret_key_abc',
            'payments.onvo.base_url' => self::ONVO,
            'payments.paypal.client_id' => 'client-id',
            'payments.paypal.client_secret' => 'client-secret',
            'payments.paypal.base_url' => self::PAYPAL,
            'payments.paypal.return_url' => 'https://app.test/api/v1/pagos/retorno',
            'payments.paypal.cancel_url' => 'https://app.test/cancelado',
            'payments.paypal.crc_per_usd' => '500',
        ]);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);
    }

    /** The whole flow with PayPal and a body of only the method: create, press twice, ask before the approval, ask after it, ask again, SINPE Móvil and refund. */
    public function test_the_whole_paypal_flow_runs_with_only_the_method(): void
    {
        $this->fakePayPal();
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);

        $created = $this->pay($reservation, ['metodo' => 'tarjeta'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.monto', 2100)
            ->assertJsonPath('data.accion_siguiente.tipo', 'redireccion')
            ->assertJsonPath('data.accion_siguiente.url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');
        $id = (string) $created->json('data.id');

        $this->pay($reservation, ['metodo' => 'tarjeta'])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('payments', 1);

        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'pendiente');
        $this->assertSame('temporary', $this->reservationStatus($reservation));
        $this->assertSame(0, $this->captures());

        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('confirmed', $this->reservationStatus($reservation));
        $this->assertSame(1, $this->captures());

        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame(1, $this->captures());

        $this->pay($reservation, ['metodo' => 'tarjeta'])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('payments', 1);

        $other = $this->bookAs($this->ana, '2030-03-05T14:00:00-06:00');
        $this->pay($other, ['metodo' => 'sinpe_movil', 'telefono' => '+50688888888', 'identificacion' => '01-1393-1919', 'tipo_identificacion' => '0'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metodo', 'errores');
        $this->assertDatabaseCount('payments', 1);

        $this->actAs($this->owner);
        $this->postJson($this->paymentUrl($id).'/reembolsos')
            ->assertStatus(409)
            ->assertJsonPath('detail', 'La pasarela con que se cobró este pago no permite reembolsos desde el sistema.');
        $this->assertSame('approved', DB::table('payments')->value('status'));
    }

    /** What PayPal does not ask for is dropped: it never reaches the provider, even when it is badly formed, and the amount of the body is ignored. */
    public function test_fields_the_gateway_does_not_use_are_ignored_and_the_amount_comes_from_the_server(): void
    {
        $this->fakePayPal();
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation, ['metodo' => 'tarjeta', 'token' => ['pm_leaked'], 'telefono' => '+50688888888', 'monto' => 1, 'amount' => 1])
            ->assertCreated()
            ->assertJsonPath('data.monto', 2100);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::PAYPAL.'/v2/checkout/orders' && $request['purchase_units'][0]['amount']['value'] === '4.20');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->body(), 'pm_leaked') || str_contains($request->body(), '50688888888'));
        $this->assertSame(2100, DB::table('payments')->value('amount'));
    }

    /** The same endpoint with ONVO Pay: its token is required, the amount of the body is ignored and the response has the same shape as PayPal's. */
    public function test_the_whole_onvo_flow_runs_with_its_token_and_answers_the_same_shape(): void
    {
        $this->fakeOnvo();
        $this->fakePayPal();
        $this->useGateway('onvo');
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation, ['metodo' => 'tarjeta'])->assertStatus(422)->assertJsonValidationErrors('token', 'errores');
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('payments')->count());

        $created = $this->pay($reservation, ['metodo' => 'tarjeta', 'token' => 'pm_test_1', 'monto' => 1, 'amount' => 1])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.monto', 2100)
            ->assertJsonPath('data.accion_siguiente.tipo', 'redireccion');
        $id = (string) $created->json('data.id');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::ONVO.'/payment-intents' && $request['amount'] === 210000);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/confirm') && $request['paymentMethodId'] === 'pm_test_1');

        $this->pay($reservation, ['metodo' => 'tarjeta', 'token' => 'pm_test_1'])->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('confirmed', $this->reservationStatus($reservation));
        $this->assertDatabaseCount('payments', 1);

        $this->useGateway('paypal');
        $other = $this->bookAs($this->ana, '2030-03-05T14:00:00-06:00');
        $paypal = $this->pay($other, ['metodo' => 'tarjeta'])->assertCreated();
        $this->assertSame(array_keys($created->json('data')), array_keys($paypal->json('data')));
        $this->assertSame(array_keys($created->json('data.accion_siguiente')), array_keys($paypal->json('data.accion_siguiente')));
    }

    /** ONVO Pay asks SINPE Móvil for its three fields. */
    public function test_onvo_asks_the_fields_of_sinpe_movil(): void
    {
        $this->fakeOnvo();
        $this->useGateway('onvo');
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation, ['metodo' => 'sinpe_movil'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['telefono', 'identificacion', 'tipo_identificacion'], 'errores');
        Http::assertNothingSent();
    }

    /** Changing the gateway of the barbershop changes what the same body needs: `tarjeta` alone is refused by ONVO Pay and accepted with PayPal. */
    public function test_changing_the_gateway_changes_what_is_required(): void
    {
        $this->fakeOnvo();
        $this->fakePayPal();
        $reservation = $this->bookAs($this->ana);

        $this->useGateway('onvo');
        $this->pay($reservation, ['metodo' => 'tarjeta'])->assertStatus(422)->assertJsonValidationErrors('token', 'errores');

        $this->useGateway('paypal');
        $this->pay($reservation, ['metodo' => 'tarjeta'])->assertCreated();
    }

    /**
     * Pay a reservation as the customer with exactly the body given.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function pay(string $reservation, array $body)
    {
        $this->actAs($this->ana);

        return $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$reservation}/pagos", $body);
    }

    /**
     * How many captures were sent to PayPal so far; it changes with every request, so it is not a pure function.
     *
     * @phpstan-impure
     */
    private function captures(): int
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/capture'))->count();
    }

    /** The owner chooses the gateway of the barbershop. */
    private function useGateway(string $gateway): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => $gateway]);
    }

    /** ONVO Pay creates the intent, leaves it waiting on a 3DS page and approves it when asked. */
    private function fakeOnvo(): void
    {
        Http::fake([
            self::ONVO.'/payment-intents' => Http::response(['id' => 'pi_1', 'status' => 'requires_payment_method']),
            self::ONVO.'/payment-intents/pi_1/confirm' => Http::response(['id' => 'pi_1', 'status' => 'requires_action', 'nextAction' => ['type' => 'redirect_to_url', 'redirectToUrl' => ['url' => 'https://secure.test/3ds']]]),
            self::ONVO.'/payment-intents/pi_1' => Http::response(['id' => 'pi_1', 'status' => 'succeeded']),
        ]);
    }

    /** PayPal creates the order; the first query finds it waiting for the customer, the next one approved, and the capture completes. */
    private function fakePayPal(): void
    {
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => Http::response(['access_token' => 'A21AAL_test_access_token', 'token_type' => 'Bearer', 'expires_in' => 32400]),
            self::PAYPAL.'/v2/checkout/orders' => Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
                ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
            ]]),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::sequence()
                ->push(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED'])
                ->push(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-1', 'status' => 'COMPLETED']]]]]], 201),
        ]);
    }

    /** Book the space as the person, who becomes a customer of Pura Navaja, and return the reservation. */
    private function bookAs(string $userId, string $startsAt = self::TUESDAY_TEN): string
    {
        $this->actAs($userId);

        return $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: $startsAt))->assertCreated()->json('data.id');
    }

    private function paymentUrl(string $paymentId): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/pagos/{$paymentId}";
    }

    private function reservationStatus(string $reservation): string
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return (string) DB::table('reservations')->where('id', $reservation)->value('status');
    }
}
