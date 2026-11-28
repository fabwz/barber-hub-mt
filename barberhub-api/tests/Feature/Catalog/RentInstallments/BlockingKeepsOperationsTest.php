<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\RentInstallments;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Payments\PaymentNoticeScenario;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/**
 * Blocking a barbershop for unpaid rent undoes nothing (PL-10, RF-58): a reservation confirmed before the block stays confirmed and the
 * notice of the gateway for a payment that was pending is still processed. Blocking only marks the state; what a blocked barbershop may
 * not do is RF-59.
 */
final class BlockingKeepsOperationsTest extends TestCase
{
    use PaymentNoticeScenario;
    use RefreshDatabaseAsOwner;

    private const string SECRET = 'onvo-test-signing-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScenario(PaymentProvider::Onvo);
        config(['payments.onvo.webhook_secret' => self::SECRET]);
    }

    /** A confirmed reservation stays confirmed and a pending payment is still settled by its notice, in a barbershop just blocked. */
    public function test_a_confirmed_reservation_and_a_gateway_notice_are_still_processed_after_the_block(): void
    {
        [, $membership] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $confirmed = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $membership, '2030-03-05T15:00:00-06:00');
        $payment = $this->pendingPayment();
        $this->overdueRent($this->puraNavaja);

        $this->assertSame(0, Artisan::call('rent:block-overdue-barbershops'));
        $this->assertNotNull(DB::table('barbershops')->where('id', $this->puraNavaja)->value('blocked_at'));

        $this->gateway->approves();
        $this->notice($this->event('evt_1', $payment['reference']))->assertOk()->assertExactJson(['recibido' => true]);

        $this->assertSame(['payment' => 'approved', 'reservation' => 'confirmed', 'refund_pending' => false], $this->statesOf($payment['payment'], $payment['reservation']));
        $this->assertSame('confirmed', DB::table('reservations')->where('id', $confirmed)->value('status'));
        $this->assertSame(1, $this->recordedNotices());
        $this->assertNotNull(DB::table('barbershops')->where('id', $this->puraNavaja)->value('blocked_at'));
    }

    /** Blocking changes no reservation and no payment: the rows are the same as before the task ran. */
    public function test_blocking_leaves_reservations_and_payments_untouched(): void
    {
        [, $membership] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $membership, '2030-03-05T15:00:00-06:00');
        $this->pendingPayment();
        $this->scopeQueriesTo($this->puraNavaja);
        $before = [DB::table('reservations')->orderBy('id')->get()->all(), DB::table('payments')->orderBy('id')->get()->all()];
        $this->overdueRent($this->puraNavaja);

        Artisan::call('rent:block-overdue-barbershops');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertEquals($before, [DB::table('reservations')->orderBy('id')->get()->all(), DB::table('payments')->orderBy('id')->get()->all()]);
    }

    /** An installment of Pura Navaja that fell due a week ago and was never paid. */
    private function overdueRent(string $barbershopId): void
    {
        $this->scopeQueriesTo($barbershopId);
        DB::table('rent_installments')->insert(['barbershop_id' => $barbershopId, 'amount' => 25000, 'due_on' => '2030-02-22']);
    }

    private function event(string $id, string $intent): string
    {
        return (string) json_encode([
            'id' => $id, 'object' => 'event', 'createdAt' => '2030-03-01T14:00:00Z', 'mode' => 'test', 'accountId' => 'acc_1', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['object' => 'payment_intent', 'id' => $intent, 'status' => 'succeeded']],
        ]);
    }

    /**
     * Send a notice as ONVO Pay does: raw JSON with the signature header.
     *
     * @return TestResponse<Response>
     */
    private function notice(string $body): TestResponse
    {
        $timestamp = $this->clock->now()->getTimestamp();
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);

        return $this->call('POST', '/api/v1/pagos/avisos/onvo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ONVO_SIGNATURE' => $signature], $body);
    }
}
