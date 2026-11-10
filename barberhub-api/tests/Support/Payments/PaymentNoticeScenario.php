<?php

declare(strict_types=1);

namespace Tests\Support\Payments;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Src\Payments\Payments\Domain\ValueObjects\PaymentMethod;
use Src\Payments\Payments\Domain\ValueObjects\PaymentProvider;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;

/**
 * The world the tests of the notices of a gateway share: Pura Navaja charges a 30 % deposit (₡2.100) with the provider under
 * test, whose gateway is simulated; a customer has a temporary reservation with a payment that is still pending. No request
 * leaves the process (PA-09).
 */
trait PaymentNoticeScenario
{
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private SimulatedPaymentGateway $gateway;

    private MutableClock $clock;

    private string $ana;

    /** Simulate the gateway of the provider for Pura Navaja and forbid any real request. */
    protected function setUpScenario(PaymentProvider $provider): void
    {
        Http::preventStrayRequests();
        $this->seedBookableBarbershops();
        $this->clock = new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica')));
        $this->app->instance(Clock::class, $this->clock);
        $this->gateway = new SimulatedPaymentGateway($provider, [PaymentMethod::Card]);
        $this->app->instance("tests.gateway.{$provider->value}", $this->gateway);
        config(['payments.gateways' => [$provider->value => "tests.gateway.{$provider->value}"]]);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => $provider->value]);
    }

    /**
     * Book a reservation as Ana and start its payment, which the provider leaves pending.
     *
     * @return array{reservation: string, payment: string, reference: string}
     */
    private function pendingPayment(): array
    {
        $this->gateway->leavesPending();
        $this->actAs($this->ana);
        $reservation = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        $payment = $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$reservation}/pagos", ['metodo' => 'tarjeta', 'token' => 'pm_test_1'])
            ->assertCreated()
            ->assertJsonPath('data.estado', 'pendiente')
            ->json('data.id');

        $this->scopeQueriesTo($this->puraNavaja);

        return ['reservation' => $reservation, 'payment' => $payment, 'reference' => (string) DB::table('payments')->where('id', $payment)->value('provider_payment_id')];
    }

    /**
     * The stored status of a payment and of a reservation of Pura Navaja.
     *
     * @return array{payment: string, reservation: string, refund_pending: bool}
     */
    private function statesOf(string $payment, string $reservation): array
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return [
            'payment' => (string) DB::table('payments')->where('id', $payment)->value('status'),
            'reservation' => (string) DB::table('reservations')->where('id', $reservation)->value('status'),
            'refund_pending' => (bool) DB::table('payments')->where('id', $payment)->value('refund_pending'),
        ];
    }

    /** Rows of the record of applied notices. */
    private function recordedNotices(): int
    {
        return DB::table('webhook_events')->count();
    }
}
