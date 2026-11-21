<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * What a cancellation or an absence does to the deposit paid online (RF-17, CA-02, CA-03, CA-04, CA-06): on time or by the barbershop the
 * approved payment is marked to give back, once; late or missed it is retained, which is an approved payment with no mark; a
 * payment that is not approved has nothing to give back; and the answer tells the customer, before and after cancelling, what happens.
 * Pura Navaja charges a deposit, the cancellation deadline is 24 hours and today is Friday 1 March 2030, 8:00.
 */
final class CancellationConsequenceTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $anaHere;

    private string $onTime;

    private string $late;

    private string $onTimePayment;

    private string $latePayment;

    /** Ana has a reservation four days ahead and one 12 hours ahead, each with an approved deposit. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo', 'payment_mode' => 'deposit', 'deposit_percentage' => 30]);
        [$this->ana, $this->anaHere] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');

        $this->onTime = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaHere, self::TUESDAY_TEN);
        $this->onTimePayment = $this->insertPayment($this->puraNavaja, $this->onTime, 'approved');
        $this->late = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaHere, '2030-03-01T20:00:00-06:00');
        $this->latePayment = $this->insertPayment($this->puraNavaja, $this->late, 'approved');
    }

    /** On time: the deposit is "a devolver", the payment stays approved and marked, and repeating the cancellation marks nothing again (CA-02, CA-06). */
    public function test_an_on_time_cancellation_marks_the_deposit_to_give_back_once(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->cancelUrl($this->onTime))
            ->assertOk()
            ->assertJsonPath('data.clasificacion', 'a_tiempo')
            ->assertJsonPath('data.deposito', 'a_devolver')
            ->assertJsonPath('data.sesion_bono', 'ninguna');
        $first = $this->payment($this->onTimePayment);

        $this->assertSame(['approved', true], [$first['status'], $first['refund_pending']]);

        $this->postJson($this->cancelUrl($this->onTime))->assertOk()->assertJsonPath('data.deposito', 'a_devolver');

        $this->assertSame($first['updated_at'], $this->payment($this->onTimePayment)['updated_at']);
        $this->assertSame(1, DB::table('payments')->where('reservation_id', $this->onTime)->count());
    }

    /** Late: the deposit is retained, so the payment stays approved with no mark (CA-03). */
    public function test_a_late_cancellation_retains_the_deposit(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->cancelUrl($this->late))
            ->assertOk()
            ->assertJsonPath('data.clasificacion', 'tardia')
            ->assertJsonPath('data.deposito', 'retenido');

        $payment = $this->payment($this->latePayment);
        $this->assertSame(['approved', false], [$payment['status'], $payment['refund_pending']]);
    }

    /** The barbershop cancels, even minutes before: the customer recovers the deposit (CA-04). */
    public function test_the_barbershop_cancelling_gives_the_deposit_back(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->cancelUrl($this->late))
            ->assertOk()
            ->assertJsonPath('data.clasificacion', 'por_la_barberia')
            ->assertJsonPath('data.deposito', 'a_devolver');

        $this->assertTrue($this->payment($this->latePayment)['refund_pending']);
    }

    /** A payment that is pending, rejected or failed is no money: nothing is marked and the answer says there is no deposit (RF-17). */
    public function test_a_payment_that_is_not_approved_has_nothing_to_give_back(): void
    {
        foreach (['pending', 'rejected', 'failed'] as $index => $status) {
            $reservation = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaHere, '2030-03-0'.(6 + $index).'T10:00:00-06:00', 'temporary');
            DB::table('reservations')->where('id', $reservation)->update(['expires_at' => '2030-03-01 08:10:00-06:00']);
            $payment = $this->insertPayment($this->puraNavaja, $reservation, $status);

            $this->actAs($this->ana);
            $this->postJson($this->cancelUrl($reservation))->assertOk()->assertJsonPath('data.deposito', 'ninguno');

            $this->assertFalse($this->payment($payment)['refund_pending'], $status);
            $this->assertSame($status, $this->payment($payment)['status']);
        }
    }

    /** A reservation with no payment at all, a barbershop that does not charge, has no deposit to decide on. */
    public function test_a_reservation_without_a_payment_has_no_deposit(): void
    {
        $reservation = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaHere, '2030-03-09T10:00:00-06:00');
        $this->actAs($this->ana);

        $this->postJson($this->cancelUrl($reservation))->assertOk()->assertJsonPath('data.deposito', 'ninguno')->assertJsonPath('data.sesion_bono', 'ninguna');
    }

    /** An absence leaves the deposit retained: the payment stays approved, with no mark, and the reservation is closed (CA-03, RF-18). */
    public function test_an_absence_retains_the_deposit(): void
    {
        $reservation = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaHere, '2030-02-28T10:00:00-06:00');
        $payment = $this->insertPayment($this->puraNavaja, $reservation, 'approved');
        $this->actAs($this->owner);

        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$reservation}/cierre", ['estado' => 'ausente'])->assertOk()->assertJsonPath('data.estado', 'ausente');

        $stored = $this->payment($payment);
        $this->assertSame(['approved', false], [$stored['status'], $stored['refund_pending']]);
    }

    /** Another barbershop's payment is never touched: the owner of Pura Navaja gets 404 on a reservation of Fade Lab and its deposit stays as it was (MT-03). */
    public function test_a_cancellation_never_marks_a_payment_of_another_barbershop(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        $anaThere = (string) Str::uuid7();
        DB::table('memberships')->insert(['id' => $anaThere, 'user_id' => $this->ana, 'barbershop_id' => $this->fadeLab, 'role' => 'customer']);
        $other = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $anaThere, self::TUESDAY_TEN, minutes: 30);
        $otherPayment = $this->insertPayment($this->fadeLab, $other, 'approved');

        $this->actAs($this->owner);
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$other}/cancelacion")->assertNotFound();
        $this->actAs($this->ana);
        $this->postJson($this->cancelUrl($this->onTime))->assertOk();

        $this->assertFalse($this->payment($otherPayment, $this->fadeLab)['refund_pending']);
        $this->assertTrue($this->payment($this->onTimePayment)['refund_pending']);
    }

    /** Before cancelling, the customer sees what would happen now: on time, "a devolver" until the limit, "retenido" after it (RF-17, CA-01). */
    public function test_the_customer_sees_the_consequence_before_cancelling(): void
    {
        $this->actAs($this->ana);

        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$this->onTime}")
            ->assertOk()
            ->assertJsonPath('data.cancelacion_ahora', [
                'clasificacion' => 'a_tiempo',
                'limite' => '2030-03-04T10:00:00-06:00',
                'deposito' => 'a_devolver',
                'sesion_bono' => 'ninguna',
            ]);
        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$this->late}")
            ->assertOk()
            ->assertJsonPath('data.cancelacion_ahora.clasificacion', 'tardia')
            ->assertJsonPath('data.cancelacion_ahora.deposito', 'retenido');

        $this->assertFalse($this->payment($this->onTimePayment)['refund_pending']);
    }

    /** The projection is for a confirmed reservation of the customer: none once cancelled or while temporary, and never in the views of the staff. */
    public function test_the_projection_is_only_for_a_confirmed_reservation_of_the_customer(): void
    {
        $temporary = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaHere, '2030-03-08T10:00:00-06:00', 'temporary');
        DB::table('reservations')->where('id', $temporary)->update(['expires_at' => '2030-03-01 08:10:00-06:00']);
        $this->actAs($this->ana);

        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$temporary}")->assertOk()->assertJsonPath('data.cancelacion_ahora', null);
        $this->postJson($this->cancelUrl($this->onTime))->assertOk();
        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$this->onTime}")->assertOk()->assertJsonPath('data.cancelacion_ahora', null);

        $this->actAs($this->owner);
        $this->getJson($this->reservationsUrl($this->puraNavaja)."/{$this->late}")->assertOk()->assertJsonMissingPath('data.cancelacion_ahora');
    }

    private function cancelUrl(string $reservationId): string
    {
        return $this->reservationsUrl($this->puraNavaja)."/{$reservationId}/cancelacion";
    }

    /** Insert the payment of a reservation, in the status given, and return its identifier. */
    private function insertPayment(string $barbershopId, string $reservationId, string $status): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('payments')->insert([
            'id' => $id, 'barbershop_id' => $barbershopId, 'reservation_id' => $reservationId, 'provider' => 'onvo', 'method' => 'card',
            'status' => $status, 'amount' => 2100, 'provider_payment_id' => 'pi_'.Str::random(8),
        ]);

        return $id;
    }

    /**
     * The stored status, mark to give back and last change of a payment.
     *
     * @return array{status: string, refund_pending: bool, updated_at: string}
     */
    private function payment(string $id, ?string $barbershopId = null): array
    {
        $this->scopeQueriesTo($barbershopId ?? $this->puraNavaja);
        $row = DB::table('payments')->where('id', $id)->first() ?? throw new \LogicException('The payment is missing.');

        return ['status' => (string) $row->status, 'refund_pending' => (bool) $row->refund_pending, 'updated_at' => (string) $row->updated_at];
    }
}
