<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Payments;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Src\Payments\Payments\Application\Contracts\ReservationConfirmation;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Contracts\Clock;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Payments\SimulatedPaymentGateway;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * Paying a temporary reservation, asking for the state of the payment and refunding it, with the gateway simulated: no
 * network is touched and no money moves (PA-09). The barbershop charges a 30 % deposit on Degradado (₡7.000), so a payment is ₡2.100.
 */
final class PaymentEndpointsTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private SimulatedPaymentGateway $gateway;

    private MutableClock $clock;

    private string $ana;

    private string $luis;

    /** Simulate the gateway of Pura Navaja, which charges a 30 % deposit with ONVO Pay, and forbid any real request. */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->gateway = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card, PaymentMethod::SinpeMovil]);
        $this->app->instance('tests.gateway.onvo', $this->gateway);
        config(['payments.gateways' => ['onvo' => 'tests.gateway.onvo']]);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->luis = $this->insertUser('Luis Mora');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);
    }

    /** A card payment of the deposit is approved, confirms the reservation and charges ₡2.100 (RF-20, PA-06). */
    public function test_a_card_payment_is_approved_and_confirms_the_reservation(): void
    {
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'aprobado')
            ->assertJsonPath('data.metodo', 'tarjeta')
            ->assertJsonPath('data.monto', 2100)
            ->assertJsonPath('data.devolucion_pendiente', false);

        $this->assertSame(2100, $this->gateway->charges[0]->amount->colones);
        $this->assertSame('confirmed', $this->reservationStatus($reservation));
        $this->assertSame(1, $this->countIn('payments', $this->puraNavaja));
    }

    /** With full payment the amount is the price; the body never sets it (PA-06). */
    public function test_the_amount_comes_from_the_policy_and_never_from_the_body(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'full', 'deposit_percentage' => null]);
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation, ['monto' => 1])->assertCreated()->assertJsonPath('data.monto', 7000);
    }

    /** A rejected payment keeps the reason and leaves the reservation temporary, so the customer tries again (RF-21). */
    public function test_a_rejected_payment_leaves_the_reservation_temporary_and_allows_a_retry(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->rejects('Fondos insuficientes');

        $this->pay($reservation)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'rechazado')
            ->assertJsonPath('data.motivo', 'Fondos insuficientes');
        $this->assertSame('temporary', $this->reservationStatus($reservation));

        $this->gateway->approves();
        $this->pay($reservation)->assertCreated()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('confirmed', $this->reservationStatus($reservation));
    }

    /** Pressing the pay button twice returns the same payment and charges once. */
    public function test_a_double_press_does_not_charge_twice(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->leavesPending();

        $first = $this->pay($reservation)->assertCreated()->assertJsonPath('data.estado', 'pendiente')->assertJsonPath('data.accion_siguiente.tipo', 'redireccion');
        $second = $this->pay($reservation)->assertOk()->assertJsonPath('data.estado', 'pendiente');

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertCount(1, $this->gateway->charges);
        $this->assertSame(1, $this->countIn('payments', $this->puraNavaja));
    }

    /** Before the provider has the charge, a communication error fails the payment, answers 503 and leaves the reservation to retry. */
    public function test_a_communication_error_before_the_charge_fails_the_payment_and_allows_a_retry(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->isUnavailable();

        $this->pay($reservation)
            ->assertStatus(503)
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, '/problemas/servicio-no-disponible'));
        $this->assertSame('failed', $this->onlyPaymentOf($reservation)->status);
        $this->assertNull($this->onlyPaymentOf($reservation)->provider_payment_id);
        $this->assertSame('temporary', $this->reservationStatus($reservation));

        $this->gateway->approves();
        $this->pay($reservation)->assertCreated()->assertJsonPath('data.estado', 'aprobado');
    }

    /** If the provider does not accept the data before creating the charge, the payment fails, the answer is a 422 that reveals nothing and the reservation can retry. */
    public function test_a_request_rejected_by_the_provider_fails_the_payment_with_a_422(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->rejectsTheRequest();

        $this->pay($reservation)
            ->assertStatus(422)
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, '/problemas/datos-invalidos'))
            ->assertJsonPath('detail', 'La pasarela no pudo procesar los datos del pago. Revise los datos e intente de nuevo.');
        $this->assertSame('failed', $this->onlyPaymentOf($reservation)->status);
        $this->assertSame('temporary', $this->reservationStatus($reservation));
    }

    /** The type of identification is "0" or 0 (and the rest of the list); anything else is a 422 on that field. */
    public function test_the_identification_type_accepts_the_listed_values_as_text_or_number(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->rejects();
        $sinpe = ['metodo' => 'sinpe_movil', 'telefono' => '+50688888888', 'identificacion' => '01-1393-1919'];

        foreach (['6', 6, '00', 'x', -1, 1.5, true, [0]] as $invalid) {
            $this->pay($reservation, [...$sinpe, 'tipo_identificacion' => $invalid])
                ->assertStatus(422)
                ->assertJsonValidationErrors('tipo_identificacion', 'errores');
        }
        $this->pay($reservation, $sinpe)->assertStatus(422)->assertJsonValidationErrors('tipo_identificacion', 'errores');
        $this->assertCount(0, $this->gateway->charges);

        foreach ([0, '0', 1, '2', 3, '4', 5, '9'] as $valid) {
            $this->pay($reservation, [...$sinpe, 'tipo_identificacion' => $valid])->assertCreated();
        }
    }

    /** After the provider has the charge, the payment stays pending, is never charged again by itself and the status query resolves it. */
    public function test_a_communication_error_after_the_charge_leaves_it_pending_for_the_status_query(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->failsAfterCreatingTheCharge();

        $this->pay($reservation)->assertStatus(503);
        $payment = $this->onlyPaymentOf($reservation);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('sim_onvo_1', $payment->provider_payment_id);

        $this->pay($reservation)->assertOk()->assertJsonPath('data.id', $payment->id)->assertJsonPath('data.estado', 'pendiente');
        $this->assertCount(1, $this->gateway->charges);

        $this->gateway->approves();
        $this->getJson($this->paymentUrl($payment->id))->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('confirmed', $this->reservationStatus($reservation));
    }

    /** SINPE Móvil stays pending after the charge and the status query approves it and confirms the reservation. */
    public function test_a_sinpe_movil_payment_is_pending_and_then_approved_by_the_status_query(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->leavesPending();

        $id = $this->pay($reservation, ['metodo' => 'sinpe_movil', 'telefono' => '+50688888888', 'identificacion' => '01-1393-1919', 'tipo_identificacion' => '0'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.metodo', 'sinpe_movil')
            ->json('data.id');
        $details = $this->gateway->charges[0]->details;
        $this->assertNotNull($details);
        $this->assertSame('+50688888888', $details->phone);
        $this->assertSame('Ana Rojas', $details->payerName);
        $this->assertSame('temporary', $this->reservationStatus($reservation));

        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'pendiente');
        $this->gateway->approves();
        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.estado', 'aprobado');
        $this->assertSame('confirmed', $this->reservationStatus($reservation));
    }

    /** The approval and the confirmation are one transaction: if the reservation fails to confirm, the payment is not approved either. */
    public function test_the_confirmation_is_atomic_with_the_approval(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->leavesPending();
        $id = $this->pay($reservation)->assertCreated()->json('data.id');

        $this->app->instance(ReservationConfirmation::class, new class implements ReservationConfirmation
        {
            public function confirm(string $reservationId, DateTimeImmutable $now): bool
            {
                throw new RuntimeException('The reservation could not be written.');
            }
        });
        $this->gateway->approves();
        $this->getJson($this->paymentUrl($id))->assertStatus(500);

        $this->assertSame('pending', $this->onlyPaymentOf($reservation)->status);
        $this->assertSame('temporary', $this->reservationStatus($reservation));
    }

    /** A payment approved after the reservation expired does not confirm it: it stays approved and marked to give back (PA-07). */
    public function test_a_payment_approved_after_the_reservation_expired_is_marked_to_give_back(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->gateway->leavesPending();
        $id = $this->pay($reservation)->assertCreated()->json('data.id');

        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 08:10:00', new DateTimeZone('America/Costa_Rica')));
        $this->gateway->approves();
        $this->getJson($this->paymentUrl($id))
            ->assertOk()
            ->assertJsonPath('data.estado', 'aprobado')
            ->assertJsonPath('data.devolucion_pendiente', true);

        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$reservation}")->assertOk()->assertJsonPath('data.estado', 'expirada');
        $this->assertNotSame('confirmed', $this->reservationStatus($reservation));

        $this->actAs($this->owner);
        $this->postJson($this->paymentUrl($id).'/reembolsos')->assertOk()->assertJsonPath('data.estado', 'reembolsado')->assertJsonPath('data.devolucion_pendiente', false);
    }

    /** An expired reservation, one already confirmed and a barbershop that does not charge cannot be paid: 409 `conflicto`. */
    public function test_a_reservation_that_cannot_be_paid_answers_409(): void
    {
        $expired = $this->bookAs($this->ana);
        $this->clock->moveTo(new DateTimeImmutable('2030-03-01 08:10:00', new DateTimeZone('America/Costa_Rica')));
        $this->pay($expired)->assertStatus(409)->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, '/problemas/conflicto'));

        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'none', 'deposit_percentage' => null, 'payment_gateway' => null]);
        $confirmed = $this->bookAs($this->luis, '2030-03-05T14:00:00-06:00');
        $this->pay($confirmed)->assertStatus(409);
        $this->assertSame([], $this->gateway->charges);
    }

    /** SINPE Móvil with a gateway that does not admit it is a 422 on `metodo`; invalid bodies are 422 too. */
    public function test_an_unsupported_method_and_invalid_bodies_answer_422(): void
    {
        $this->gateway = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card]);
        $this->app->instance('tests.gateway.onvo', $this->gateway);
        $reservation = $this->bookAs($this->ana);

        $this->pay($reservation, ['metodo' => 'sinpe_movil', 'telefono' => '+50688888888', 'identificacion' => '01-1393-1919', 'tipo_identificacion' => '0'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('metodo', 'errores');
        $this->pay($reservation, ['metodo' => 'tarjeta', 'token' => null])->assertStatus(422)->assertJsonValidationErrors('token', 'errores');
        $this->pay($reservation, ['metodo' => 'efectivo'])->assertStatus(422)->assertJsonValidationErrors('metodo', 'errores');
        $this->assertSame([], $this->gateway->charges);
    }

    /** The owner refunds an approved payment: it becomes refunded; a refusal leaves it approved with the reason; a payment not approved cannot be refunded. */
    public function test_the_owner_refunds_an_approved_payment(): void
    {
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');

        $this->actAs($this->owner);
        $this->gateway->refusesRefunds('Saldo del comercio insuficiente');
        $this->postJson($this->paymentUrl($id).'/reembolsos')
            ->assertStatus(409)
            ->assertJsonPath('motivo', 'Saldo del comercio insuficiente');
        $this->assertSame('approved', $this->onlyPaymentOf($reservation)->status);

        $this->gateway = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card, PaymentMethod::SinpeMovil]);
        $this->app->instance('tests.gateway.onvo', $this->gateway);
        $this->postJson($this->paymentUrl($id).'/reembolsos')->assertOk()->assertJsonPath('data.estado', 'reembolsado');
        $this->assertSame([[$this->onlyPaymentOf($reservation)->provider_payment_id, 2100]], array_map(fn (array $refund): array => [$refund[0], $refund[1]->colones], $this->gateway->refunds));

        $this->postJson($this->paymentUrl($id).'/reembolsos')->assertStatus(409);
    }

    /** Who may do what: a customer of another reservation or a stranger gets 404, a collaborator 403, and only the owner refunds. */
    public function test_the_permissions_of_each_role(): void
    {
        $reservation = $this->bookAs($this->ana);
        $this->bookAs($this->luis, '2030-03-05T14:00:00-06:00');
        $this->actAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');
        $collaborator = (string) DB::table('collaborators')->where('id', $this->daniela)->value('user_id');
        $stranger = $this->insertUser('Persona ajena');

        $this->actAs($this->luis);
        $this->pay($reservation)->assertNotFound();
        $this->getJson($this->paymentUrl($id))->assertNotFound();
        $this->postJson($this->paymentUrl($id).'/reembolsos')->assertForbidden();

        $this->actAs($stranger);
        $this->pay($reservation)->assertNotFound();
        $this->getJson($this->paymentUrl($id))->assertNotFound();

        $this->actAs($collaborator);
        $this->pay($reservation)->assertForbidden();
        $this->getJson($this->paymentUrl($id))->assertForbidden();
        $this->postJson($this->paymentUrl($id).'/reembolsos')->assertForbidden();

        $this->actAs($this->owner);
        $this->pay($reservation)->assertForbidden();
        $this->getJson($this->paymentUrl($id))->assertOk()->assertJsonPath('data.id', $id);

        $this->actAs($this->ana);
        $this->postJson($this->paymentUrl($id).'/reembolsos')->assertForbidden();
        $this->getJson($this->paymentUrl($id))->assertOk();
    }

    /** A payment or a reservation of another barbershop reads like one that does not exist. */
    public function test_another_barbershop_is_not_reachable(): void
    {
        $reservation = $this->bookAs($this->ana);
        $id = $this->pay($reservation)->assertCreated()->json('data.id');
        $this->grantRole($this->owner, $this->fadeLab, 'owner');

        $this->actAs($this->owner);
        $this->getJson("/api/v1/barberias/{$this->fadeLab}/pagos/{$id}")->assertNotFound();
        $this->postJson("/api/v1/barberias/{$this->fadeLab}/pagos/{$id}/reembolsos")->assertNotFound();
    }

    /** Book the Tuesday space as the person, who becomes a customer of Pura Navaja, and return the reservation. */
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

    /** Address of a payment of Pura Navaja. */
    private function paymentUrl(string $paymentId): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/pagos/{$paymentId}";
    }

    /** The status of a reservation as it is stored. */
    private function reservationStatus(string $reservation): string
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return (string) DB::table('reservations')->where('id', $reservation)->value('status');
    }

    /** The only payment a reservation has. */
    private function onlyPaymentOf(string $reservation): PaymentRow
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $payments = DB::table('payments')->where('reservation_id', $reservation)->get();
        $this->assertCount(1, $payments);
        $row = $payments->first();
        $this->assertNotNull($row);

        return new PaymentRow((string) $row->id, (string) $row->status, $row->provider_payment_id);
    }
}

/** The columns of a stored payment that the test reads. */
final readonly class PaymentRow
{
    public function __construct(public string $id, public string $status, public ?string $provider_payment_id) {}
}
