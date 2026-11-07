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
 * The reservation tells the customer how much to pay now, what stays for the barbershop and until when (RF-20, RF-08).
 * The amount is the one the charge asks, by the policy of the barbershop (PA-01, PA-06); the deadline is the one of the
 * temporary reservation (RE-05). Degradado costs ₡7.000 and the gateway is simulated: no network, no money (PA-09).
 */
final class ReservationPaymentDueTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private SimulatedPaymentGateway $gateway;

    private string $ana;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->seedBookableBarbershops();
        $this->app->instance(Clock::class, new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'))));
        $this->gateway = new SimulatedPaymentGateway(PaymentProvider::Onvo, [PaymentMethod::Card, PaymentMethod::SinpeMovil]);
        $this->app->instance('tests.gateway.onvo', $this->gateway);
        config(['payments.gateways' => ['onvo' => 'tests.gateway.onvo']]);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo']);
    }

    /** Without payment there is nothing to pay now, the whole price stays for the barbershop and there is no deadline. */
    public function test_a_barbershop_without_payment_owes_nothing_and_has_no_deadline(): void
    {
        $this->policy('none');

        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.monto_a_pagar', 0)
            ->assertJsonPath('data.saldo_en_local', 7000)
            ->assertJsonPath('data.vence_en', null);
    }

    /** A 30 % deposit of ₡7.000 is ₡2.100 now and ₡4.900 in the barbershop, until the 10 minutes of the temporary reservation pass. */
    public function test_a_deposit_is_owed_until_the_deadline(): void
    {
        $this->policy('deposit', 30);

        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.estado', 'temporal')
            ->assertJsonPath('data.monto_a_pagar', 2100)
            ->assertJsonPath('data.saldo_en_local', 4900)
            ->assertJsonPath('data.vence_en', '2030-03-01T08:10:00-06:00')
            ->assertJsonPath('data.expira_en', '2030-03-01T08:10:00-06:00');
    }

    /** The deposit rounds up to the colon: 33 % of ₡5.001 is ₡1.651, not ₡1.650. */
    public function test_the_deposit_rounds_up_to_the_colon(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('services')->where('id', $this->degradado)->update(['price' => 5001]);
        $this->policy('deposit', 33);

        $this->book()->assertCreated()->assertJsonPath('data.monto_a_pagar', 1651)->assertJsonPath('data.saldo_en_local', 3350);
    }

    /** With full payment the amount is the price and nothing stays for the barbershop. */
    public function test_a_full_payment_owes_the_whole_price(): void
    {
        $this->policy('full');

        $this->book()
            ->assertCreated()
            ->assertJsonPath('data.monto_a_pagar', 7000)
            ->assertJsonPath('data.saldo_en_local', 0)
            ->assertJsonPath('data.vence_en', '2030-03-01T08:10:00-06:00');
    }

    /** The amount shown is the amount the real payment charges, whatever the policy. */
    public function test_the_amount_is_the_one_the_payment_charges(): void
    {
        $starts = ['2030-03-05T10:00:00-06:00', '2030-03-05T14:00:00-06:00', '2030-03-05T16:00:00-06:00'];

        foreach ([['deposit', 30], ['deposit', 33], ['full', null]] as $index => [$mode, $percentage]) {
            $this->policy($mode, $percentage);
            $this->actAs($this->ana);
            $created = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: $starts[$index]))->assertCreated();
            $shown = $created->json('data.monto_a_pagar');

            $payment = $this->postJson($this->reservationsUrl($this->puraNavaja).'/'.$created->json('data.id').'/pagos', ['metodo' => 'tarjeta', 'token' => 'pm_test_1'])->assertCreated();

            $this->assertGreaterThan(0, $shown);
            $this->assertSame($shown, $payment->json('data.monto'));
            $this->assertSame($shown, $this->gateway->charges[$index]->amount->colones);
        }
    }

    /** Once the payment is approved the reservation is confirmed: nothing is owed and there is no deadline. */
    public function test_nothing_is_owed_after_an_approved_payment(): void
    {
        $this->policy('deposit', 30);
        $id = $this->book()->assertCreated()->json('data.id');
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/pagos", ['metodo' => 'tarjeta', 'token' => 'pm_test_1'])->assertCreated()->assertJsonPath('data.estado', 'aprobado');

        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.monto_a_pagar', 0)
            ->assertJsonPath('data.vence_en', null);
    }

    /** A temporary reservation past its window reads as expired and owes nothing. */
    public function test_an_expired_reservation_owes_nothing(): void
    {
        $this->policy('deposit', 30);
        $id = $this->book()->assertCreated()->json('data.id');
        $this->app->instance(Clock::class, new MutableClock(new DateTimeImmutable('2030-03-01 08:11:00', new DateTimeZone('America/Costa_Rica'))));

        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$id}")
            ->assertOk()
            ->assertJsonPath('data.estado', 'expirada')
            ->assertJsonPath('data.monto_a_pagar', 0)
            ->assertJsonPath('data.vence_en', null);
    }

    /** The three attributes come when it is made, in the list and in the detail, with the same values. */
    public function test_the_three_attributes_come_in_create_list_and_detail(): void
    {
        $this->policy('deposit', 30);
        $id = $this->book()->assertCreated()->json('data.id');
        $expected = ['monto_a_pagar' => 2100, 'saldo_en_local' => 4900, 'vence_en' => '2030-03-01T08:10:00-06:00'];

        $list = $this->getJson($this->myReservationsUrl($this->puraNavaja))->assertOk();
        $detail = $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$id}")->assertOk();

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $list->json("data.0.{$key}"));
            $this->assertSame($value, $detail->json("data.{$key}"));
        }
    }

    /** Each barbershop quotes by its own policy, and the reads of the staff carry no quote. */
    public function test_each_barbershop_quotes_by_its_own_policy_and_the_staff_do_not_get_it(): void
    {
        $this->policy('deposit', 30);
        $this->scopeQueriesTo($this->fadeLab);
        DB::table('barbershops')->where('id', $this->fadeLab)->update(['payment_mode' => 'none']);
        $this->actAs($this->ana);
        $elsewhere = $this->postJson($this->reservationsUrl($this->fadeLab), $this->booking($this->corte, $this->mauricio))->assertCreated();
        $this->assertSame(0, $elsewhere->json('data.monto_a_pagar'));

        $this->book()->assertCreated()->assertJsonPath('data.monto_a_pagar', 2100);
        $this->actAs($this->owner);
        $this->getJson($this->reservationsUrl($this->puraNavaja))->assertOk()->assertJsonMissingPath('data.0.monto_a_pagar');
    }

    /** Set how Pura Navaja charges. */
    private function policy(string $mode, ?int $percentage = null): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => $mode, 'deposit_percentage' => $percentage]);
    }

    /**
     * Book the Tuesday space of Pura Navaja as Ana.
     *
     * @return TestResponse<Response>
     */
    private function book(): TestResponse
    {
        $this->actAs($this->ana);

        return $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking());
    }
}
