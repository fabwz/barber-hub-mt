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
 * The two real adapters, with the answers of their providers simulated: changing the gateway of the barbershop (a column, no
 * code) sends the same flow of the same endpoints through the other adapter, with the same responses (RF-23). No request
 * leaves the process and no money moves (PA-09). Pura Navaja charges a 30 % deposit on Degradado (₡7.000), so a payment is ₡2.100.
 */
final class GatewaySwitchTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const string ONVO = 'https://api.onvopay.com/v1';

    private const string PAYPAL = 'https://api-m.sandbox.paypal.com';

    private string $ana;

    private string $luis;

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
        $this->luis = $this->insertUser('Luis Mora');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);
    }

    /** The same flow with ONVO Pay and with PayPal: same statuses, same fields, same state at the end. Only the configuration of the barbershop changes. */
    public function test_changing_the_gateway_of_the_barbershop_runs_the_same_flow_through_the_other_adapter(): void
    {
        $this->fakeOnvo();
        $this->fakePayPal();

        $this->useGateway('onvo');
        $onvo = $this->payAndConfirm($this->bookAs($this->ana), $this->ana);

        $this->useGateway('paypal');
        $paypal = $this->payAndConfirm($this->bookAs($this->luis, '2030-03-05T14:00:00-06:00'), $this->luis);

        $this->assertSame($onvo['created']['status'], $paypal['created']['status']);
        $this->assertSame(array_keys($onvo['created']['body']['data']), array_keys($paypal['created']['body']['data']));
        $this->assertSame(array_keys($onvo['created']['body']['data']['accion_siguiente']), array_keys($paypal['created']['body']['data']['accion_siguiente']));
        $this->assertSame('pendiente', $paypal['created']['body']['data']['estado']);
        $this->assertSame('redireccion', $paypal['created']['body']['data']['accion_siguiente']['tipo']);
        $this->assertSame(2100, $paypal['created']['body']['data']['monto']);
        $this->assertSame($onvo['shown']['status'], $paypal['shown']['status']);
        $this->assertSame(array_keys($onvo['shown']['body']['data']), array_keys($paypal['shown']['body']['data']));
        $this->assertSame('aprobado', $onvo['shown']['body']['data']['estado']);
        $this->assertSame('aprobado', $paypal['shown']['body']['data']['estado']);
        $this->assertSame(['confirmed', 'confirmed'], [$onvo['reservation'], $paypal['reservation']]);

        $this->assertSame(['onvo', 'paypal'], DB::table('payments')->orderBy('created_at')->pluck('provider')->all());
        $stored = DB::table('payments')->where('provider', 'paypal')->first();
        $this->assertNotNull($stored);
        $this->assertSame('USD', $stored->provider_currency);
        $this->assertSame(420, $stored->provider_amount);
        $this->assertSame(5000000, $stored->provider_exchange_rate);
        $this->assertSame('ORDER-1', $stored->provider_payment_id);
        $this->assertSame('CAPTURE-1', $stored->provider_capture_id);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::PAYPAL.'/v2/checkout/orders' && $request['purchase_units'][0]['amount']['value'] === '4.20');
    }

    /** A payment keeps the provider it was created with: changing the gateway of the barbershop in the middle does not move it to the other adapter. */
    public function test_a_payment_in_course_keeps_its_provider_when_the_gateway_changes(): void
    {
        $this->fakeOnvo();
        $this->fakePayPal();
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');

        $this->useGateway('onvo');
        $this->actAs($this->ana);
        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');

        Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), self::ONVO));
    }

    /** SINPE Móvil is not admitted by PayPal: the same 422 on `metodo` as any provider that does not admit a method, and nothing is sent. */
    public function test_sinpe_movil_is_refused_with_paypal(): void
    {
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation, ['metodo' => 'sinpe_movil', 'telefono' => '+50688888888', 'identificacion' => '01-1393-1919', 'tipo_identificacion' => '0'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metodo', 'errores');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('payments')->count());
    }

    /** A declined capture is a rejected payment with its reason, and the reservation stays temporary to try again. */
    public function test_a_declined_capture_rejects_the_payment_and_leaves_the_reservation_temporary(): void
    {
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => $this->token(),
            self::PAYPAL.'/v2/checkout/orders' => $this->createdOrder(),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument presented was declined.']]], 422),
        ]);
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');

        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'rechazado')->assertJsonPath('data.motivo', 'The instrument presented was declined.');

        $this->assertSame('temporary', $this->reservationStatus($reservation));
    }

    /** Before the order exists a communication error fails the payment (503) and the reservation stays temporary to try again. */
    public function test_a_communication_error_before_the_order_fails_the_payment_and_allows_a_retry(): void
    {
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => $this->token(),
            self::PAYPAL.'/v2/checkout/orders' => Http::sequence()->push(['message' => 'down'], 503)->push(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1']]], 200),
        ]);
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation)->assertStatus(503)->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, '/problemas/servicio-no-disponible'));
        $this->assertSame('failed', DB::table('payments')->value('status'));
        $this->assertSame('temporary', $this->reservationStatus($reservation));

        $this->pay($reservation)->assertCreated()->assertJsonPath('data.estado', 'pendiente');
        $this->assertSame(2, DB::table('payments')->count());
    }

    /** After the order exists a communication error leaves the payment pending, nobody is charged again by itself and the next query resolves it. */
    public function test_a_communication_error_while_capturing_leaves_the_payment_pending_and_the_next_query_resolves_it(): void
    {
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => $this->token(),
            self::PAYPAL.'/v2/checkout/orders' => $this->createdOrder(),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::sequence()->push(['message' => 'down'], 500)->push($this->completedOrder(), 201),
        ]);
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');

        $this->getJson($this->paymentUrl($id))->assertStatus(503);
        $this->assertSame('pending', DB::table('payments')->value('status'));
        $this->assertSame('temporary', $this->reservationStatus($reservation));

        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('confirmed', $this->reservationStatus($reservation));
        $this->assertSame(1, DB::table('payments')->count());
    }

    /** Asking again about an approved payment does not call the provider: there is no second capture. */
    public function test_asking_again_after_the_approval_does_not_capture_again(): void
    {
        $this->fakePayPal();
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');

        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');

        $captures = 0;
        Http::assertSent(function (Request $request) use (&$captures): bool {
            $captures += str_ends_with($request->url(), '/capture') ? 1 : 0;

            return true;
        });
        $this->assertSame(1, $captures);
    }

    /** The refund of a payment charged with PayPal is not implemented: the owner gets a clear 409 `conflicto`, the payment stays approved and PayPal is not asked. */
    public function test_a_refund_of_a_paypal_payment_answers_that_it_is_not_supported(): void
    {
        $this->fakePayPal();
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');
        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');

        $this->actAs($this->owner);
        $this->postJson($this->paymentUrl($id).'/reembolsos')
            ->assertStatus(409)
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, '/problemas/conflicto'))
            ->assertJsonPath('detail', 'La pasarela con que se cobró este pago no permite reembolsos desde el sistema.');

        $this->assertSame('approved', DB::table('payments')->value('status'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/refund'));
    }

    /** The return address is public, answers only a message and changes nothing, whatever it receives. */
    public function test_the_return_address_only_answers_a_message(): void
    {
        $this->fakePayPal();
        $this->useGateway('paypal');
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');
        $before = DB::table('payments')->get()->all();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/pagos/retorno?token=ORDER-1&PayerID=FAKE&estado=aprobado&pago='.$id)
            ->assertOk()
            ->assertExactJson(['mensaje' => 'Regresaste del proveedor de pago: consulta el estado de tu pago.']);

        $this->assertEquals($before, DB::table('payments')->get()->all());
        $this->assertSame('pending', DB::table('payments')->value('status'));
        $this->assertSame('temporary', $this->reservationStatus($reservation));
    }

    /**
     * Pay, ask for the state and read the reservation: what each step answered.
     *
     * @return array{created: array{status: int, body: array<string, mixed>}, shown: array{status: int, body: array<string, mixed>}, reservation: string}
     */
    private function payAndConfirm(string $reservation, string $userId): array
    {
        $this->actAs($userId);
        $created = $this->pay($reservation);
        $id = (string) $created->json('data.id');
        $shown = $this->getJson($this->paymentUrl($id));

        return [
            'created' => ['status' => $created->getStatusCode(), 'body' => $created->json()],
            'shown' => ['status' => $shown->getStatusCode(), 'body' => $shown->json()],
            'reservation' => $this->reservationStatus($reservation),
        ];
    }

    /** The owner chooses the gateway of the barbershop: the only difference between the two runs. */
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

    /** PayPal creates the order, the customer approves it and the capture completes. */
    private function fakePayPal(): void
    {
        Http::fake([
            self::PAYPAL.'/v1/oauth2/token' => $this->token(),
            self::PAYPAL.'/v2/checkout/orders' => $this->createdOrder(),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1' => Http::response(['id' => 'ORDER-1', 'status' => 'APPROVED']),
            self::PAYPAL.'/v2/checkout/orders/ORDER-1/capture' => Http::response($this->completedOrder(), 201),
        ]);
    }

    private function token(): mixed
    {
        return Http::response(['access_token' => 'A21AAL_test_access_token', 'token_type' => 'Bearer', 'expires_in' => 32400]);
    }

    private function createdOrder(): mixed
    {
        return Http::response(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
            ['rel' => 'self', 'href' => self::PAYPAL.'/v2/checkout/orders/ORDER-1'],
            ['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1'],
        ]]);
    }

    /** @return array<string, mixed> */
    private function completedOrder(): array
    {
        return ['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [['payments' => ['captures' => [['id' => 'CAPTURE-1', 'status' => 'COMPLETED']]]]]];
    }

    /** Book the space as the person, who becomes a customer of Pura Navaja, and return the reservation. */
    private function bookAs(string $userId, string $startsAt = self::TUESDAY_TEN): string
    {
        $this->actAs($userId);

        return $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: $startsAt))->assertCreated()->json('data.id');
    }

    /**
     * Pay a reservation as the person of the session, with a card unless the body says otherwise.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    private function pay(string $reservation, array $body = [])
    {
        return $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$reservation}/pagos", ['metodo' => 'tarjeta', 'token' => 'pm_test_1', ...$body]);
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
