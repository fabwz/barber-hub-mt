<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
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
 * The reservation tells the customer which gateway to pay through and with which methods (RF-20, PA-01, PA-02). The methods
 * are what the gateway declares, never written in the resource. Both gateways are simulated: no network, no money (PA-09).
 */
final class ReservationPaymentChannelTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const array SINPE = ['metodo' => 'sinpe_movil', 'telefono' => '+50688888888', 'identificacion' => '01-1393-1919', 'tipo_identificacion' => '0'];

    private SimulatedPaymentGateway $onvo;

    private string $ana;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seedBookableBarbershops();
        $this->app->instance(Clock::class, new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'))));
        $this->onvo = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card, PaymentMethod::SinpeMovil]);
        $this->app->instance('tests.gateway.onvo', $this->onvo);
        $this->app->instance('tests.gateway.paypal', new SimulatedPaymentGateway(PaymentProvider::Paypal, [PaymentMethod::Card]));
        config(['payments.gateways' => ['onvo' => 'tests.gateway.onvo', 'paypal' => 'tests.gateway.paypal']]);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->charges($this->puraNavaja, 'onvo', 'deposit', 30);
    }

    /** Pura Navaja collects with ONVO Pay: card and SINPE Móvil. */
    public function test_a_barbershop_with_onvo_admits_card_and_sinpe_movil(): void
    {
        $this->book($this->puraNavaja)
            ->assertCreated()
            ->assertJsonPath('data.pasarela', 'onvo')
            ->assertJsonPath('data.metodos_de_pago', ['tarjeta', 'sinpe_movil']);
    }

    /** Fade Lab collects with PayPal: card only. */
    public function test_a_barbershop_with_paypal_admits_only_card(): void
    {
        $this->charges($this->fadeLab, 'paypal', 'full');

        $this->book($this->fadeLab, $this->corte, $this->mauricio)
            ->assertCreated()
            ->assertJsonPath('data.pasarela', 'paypal')
            ->assertJsonPath('data.metodos_de_pago', ['tarjeta']);
    }

    /** Don Chepe does not charge: no gateway and no methods, even though the reservation is confirmed at once. */
    public function test_a_barbershop_that_does_not_charge_has_no_gateway(): void
    {
        $this->charges($this->fadeLab, null, 'none');

        $this->book($this->fadeLab, $this->corte, $this->mauricio)
            ->assertCreated()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.pasarela', null)
            ->assertJsonPath('data.metodos_de_pago', []);
    }

    /** A reservation that owes nothing anymore (paid, so confirmed) has no gateway to show. */
    public function test_a_paid_reservation_has_no_gateway(): void
    {
        $id = $this->book($this->puraNavaja)->assertCreated()->json('data.id');
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/pagos", ['metodo' => 'tarjeta', 'token' => 'pm_test_1'])->assertCreated();

        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.monto_a_pagar', 0)
            ->assertJsonPath('data.pasarela', null)
            ->assertJsonPath('data.metodos_de_pago', []);
    }

    /** With a payment already started the gateway is the one of that payment, even if the barbershop changes it afterwards (PA-02). */
    public function test_a_started_payment_keeps_its_gateway_when_the_barbershop_changes_it(): void
    {
        $this->onvo->leavesPending();
        $id = $this->book($this->puraNavaja)->assertCreated()->json('data.id');
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/pagos", self::SINPE)->assertCreated()->assertJsonPath('data.estado', 'pendiente');
        $this->charges($this->puraNavaja, 'paypal', 'deposit', 30);

        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.pasarela', 'onvo')
            ->assertJsonPath('data.metodos_de_pago', ['tarjeta', 'sinpe_movil']);
        $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk()->assertJsonPath('data.0.pasarela', 'onvo');

        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))
            ->assertCreated()
            ->assertJsonPath('data.pasarela', 'paypal')
            ->assertJsonPath('data.metodos_de_pago', ['tarjeta']);
    }

    /** Create, list and detail carry both attributes with the same values. */
    public function test_both_attributes_come_in_create_list_and_detail(): void
    {
        $id = $this->book($this->puraNavaja)->assertCreated()->json('data.id');

        $list = $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk();
        $detail = $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$id}")->assertOk();

        $this->assertSame('onvo', $list->json('data.0.pasarela'));
        $this->assertSame(['tarjeta', 'sinpe_movil'], $list->json('data.0.metodos_de_pago'));
        $this->assertSame('onvo', $detail->json('data.pasarela'));
        $this->assertSame(['tarjeta', 'sinpe_movil'], $detail->json('data.metodos_de_pago'));
    }

    /** The list mixes reservations with and without a started payment, and each one gets its own gateway. */
    public function test_a_list_gives_each_reservation_its_own_gateway(): void
    {
        $this->onvo->leavesPending();
        $first = $this->book($this->puraNavaja)->assertCreated()->json('data.id');
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$first}/pagos", self::SINPE)->assertCreated();
        $this->charges($this->puraNavaja, 'paypal', 'deposit', 30);
        $this->actAs($this->ana);
        $second = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated()->json('data.id');

        $list = $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk();
        $gateways = [];
        foreach ((array) $list->json('data') as $item) {
            $gateways[$item['id']] = $item['pasarela'];
        }

        $this->assertSame('onvo', $gateways[$first]);
        $this->assertSame('paypal', $gateways[$second]);
    }

    /** The gateway of another barbershop never leaks: each one reads its own. */
    public function test_the_gateway_of_another_barbershop_does_not_leak(): void
    {
        $this->charges($this->fadeLab, 'paypal', 'full');
        $this->book($this->fadeLab, $this->corte, $this->mauricio)->assertCreated()->assertJsonPath('data.pasarela', 'paypal');

        $this->book($this->puraNavaja)->assertCreated()->assertJsonPath('data.pasarela', 'onvo');
        $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk()->assertJsonCount(1, 'data');
    }

    /** Set how a barbershop charges and through which gateway. */
    private function charges(string $barbershopId, ?string $gateway, string $mode, ?int $percentage = null): void
    {
        $this->scopeQueriesTo($barbershopId);
        DB::table('barbershops')->where('id', $barbershopId)->update(['payment_gateway' => $gateway, 'payment_mode' => $mode, 'deposit_percentage' => $percentage]);
    }

    /**
     * Book as Ana.
     *
     * @return TestResponse<Response>
     */
    private function book(string $barbershopId, ?string $serviceId = null, ?string $collaboratorId = null): TestResponse
    {
        $this->actAs($this->ana);

        return $this->postJson($this->reservationsUrl($barbershopId), $this->booking($serviceId, $collaboratorId));
    }
}
