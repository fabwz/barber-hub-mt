<?php

declare(strict_types=1);

namespace Tests\Feature\Payments\Income;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Tenancy\BuildsAccessScenario;
use Tests\TestCase;

/**
 * The income report of the owner (RF-25, PA-11, PA-10, MT-02, CO-04). Pura Navaja has its owner, the collaborators Daniela and Kevin
 * and the customers Ana and Luis; Fade Lab has its own owner. Every figure is written straight into the tables, in colones, and
 * the days are those of Costa Rica (UTC-6).
 */
final class IncomeReportTest extends TestCase
{
    use BuildsAccessScenario;
    use RefreshDatabaseAsOwner;

    /** Day of May 2030 handed to each reservation that carries a payment, so no two of them share a space. */
    private int $nextDay = 1;

    /** A voucher definition of Pura Navaja, for the vouchers of the tests. */
    private string $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildAccessScenario();
        $this->definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
    }

    /** The figures match the payments, refunds, vouchers and services of the range, and each one stays apart (RF-25, PA-11, PA-10). */
    public function test_the_totals_match_the_payments_and_refunds_of_the_range(): void
    {
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 5000, '2030-03-05 10:00:00-06');
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 3000, '2030-03-05 15:00:00-06');
        $this->insertOnlinePayment($this->puraNavaja, 'refunded', 4000, '2030-03-10 09:00:00-06', '2030-03-12 11:00:00-06');
        // A payment marked to give back is still approved: the money is still held, so it counts and nothing is taken off.
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 2000, '2030-03-20 09:00:00-06', refundPending: true);
        // Not charged, or outside the range: none of them counts.
        $this->insertOnlinePayment($this->puraNavaja, 'pending', 9000, '2030-03-15 09:00:00-06');
        $this->insertOnlinePayment($this->puraNavaja, 'rejected', 1500, '2030-03-15 10:00:00-06');
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 7777, '2030-02-28 10:00:00-06');
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 6666, '2030-04-01 10:00:00-06');

        $first = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        $second = $this->insertPurchasedVoucher($this->puraNavaja, $this->luisMembership, $this->definition);
        $this->activateVoucherOn($this->puraNavaja, $second, '2030-03-15 09:00:00-06');
        $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition, status: 'pending');
        $late = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $this->definition);
        $this->activateVoucherOn($this->puraNavaja, $late, '2030-04-02 09:00:00-06');
        // The payment of a voucher is the sale of the voucher: it is not counted again as charged online.
        $this->insertVoucherPayment($this->puraNavaja, $first, 30000, '2030-03-01 08:00:00-06');

        $this->insertAttended('2030-03-08 10:00:00-06');
        $this->insertAttended('2030-03-08 15:00:00-06');
        // Paid with a session of a voucher, it is worth its whole price all the same.
        $withVoucher = $this->insertAttended('2030-03-25 10:00:00-06');
        DB::table('reservations')->where('id', $withVoucher)->update(['purchased_voucher_id' => $first]);
        // Only a completed reservation is an attended service.
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, '2030-03-09 10:00:00-06', 'no_show');
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, '2030-03-09 12:00:00-06', 'confirmed');
        $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, '2030-03-09 15:00:00-06', 'cancelled_by_customer');
        $this->insertAttended('2030-04-02 10:00:00-06');

        $response = $this->report($this->puraNavaja, '2030-03-01', '2030-03-31')->assertOk();

        $response->assertJsonPath('data.desde', '2030-03-01')
            ->assertJsonPath('data.hasta', '2030-03-31')
            ->assertJsonPath('data.cobrado_en_linea', ['bruto' => 14000, 'reembolsado' => 4000, 'neto' => 10000])
            ->assertJsonPath('data.bonos_vendidos', ['total' => 60000, 'cantidad' => 2])
            ->assertJsonPath('data.servicios_atendidos', ['total' => 21000, 'cantidad' => 3]);
        $this->assertSame([
            ['fecha' => '2030-03-01', 'cobrado' => 0, 'reembolsado' => 0, 'neto' => 0, 'bonos_vendidos' => 30000, 'servicios_atendidos' => 0],
            ['fecha' => '2030-03-05', 'cobrado' => 8000, 'reembolsado' => 0, 'neto' => 8000, 'bonos_vendidos' => 0, 'servicios_atendidos' => 0],
            ['fecha' => '2030-03-08', 'cobrado' => 0, 'reembolsado' => 0, 'neto' => 0, 'bonos_vendidos' => 0, 'servicios_atendidos' => 14000],
            ['fecha' => '2030-03-10', 'cobrado' => 4000, 'reembolsado' => 0, 'neto' => 4000, 'bonos_vendidos' => 0, 'servicios_atendidos' => 0],
            ['fecha' => '2030-03-12', 'cobrado' => 0, 'reembolsado' => 4000, 'neto' => -4000, 'bonos_vendidos' => 0, 'servicios_atendidos' => 0],
            ['fecha' => '2030-03-15', 'cobrado' => 0, 'reembolsado' => 0, 'neto' => 0, 'bonos_vendidos' => 30000, 'servicios_atendidos' => 0],
            ['fecha' => '2030-03-20', 'cobrado' => 2000, 'reembolsado' => 0, 'neto' => 2000, 'bonos_vendidos' => 0, 'servicios_atendidos' => 0],
            ['fecha' => '2030-03-25', 'cobrado' => 0, 'reembolsado' => 0, 'neto' => 0, 'bonos_vendidos' => 0, 'servicios_atendidos' => 7000],
        ], $response->json('data.detalle'));
        // No figure is a combined total, and the balance paid in the barbershop is nowhere (PA-11, PA-10).
        $response->assertJsonMissingPath('data.total')->assertJsonMissingPath('data.saldo')->assertJsonMissingPath('data.saldo_pendiente');
    }

    /** A refund belongs to the day its payment last changed (`updated_at`), not to the day it was charged: RF-24 will bring its own dates. */
    public function test_a_refund_is_placed_by_the_updated_at_of_its_payment(): void
    {
        $payment = $this->insertOnlinePayment($this->puraNavaja, 'refunded', 5000, '2030-03-02 10:00:00-06', '2030-04-10 10:00:00-06');

        $this->report($this->puraNavaja, '2030-03-01', '2030-03-31')->assertOk()
            ->assertJsonPath('data.cobrado_en_linea', ['bruto' => 5000, 'reembolsado' => 0, 'neto' => 5000]);
        $this->report($this->puraNavaja, '2030-04-01', '2030-04-30')->assertOk()
            ->assertJsonPath('data.cobrado_en_linea', ['bruto' => 0, 'reembolsado' => 5000, 'neto' => -5000]);

        // Moving only `updated_at` moves the refund and leaves the charge where it was.
        DB::table('payments')->where('id', $payment)->update(['updated_at' => '2030-05-02 10:00:00-06']);

        $this->report($this->puraNavaja, '2030-04-01', '2030-04-30')->assertOk()
            ->assertJsonPath('data.cobrado_en_linea', ['bruto' => 0, 'reembolsado' => 0, 'neto' => 0]);
        $this->report($this->puraNavaja, '2030-05-01', '2030-05-31')->assertOk()
            ->assertJsonPath('data.cobrado_en_linea.reembolsado', 5000);
        $this->report($this->puraNavaja, '2030-03-01', '2030-03-31')->assertOk()
            ->assertJsonPath('data.cobrado_en_linea.bruto', 5000);
    }

    /** The range is from 00:00 of the first day to 00:00 of the day after the last, in Costa Rica (UTC-6), both days included. */
    public function test_the_range_is_measured_in_the_time_of_costa_rica(): void
    {
        // 23:30 of 1 March in Costa Rica is already 2 March in UTC; it still belongs to 1 March.
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 1000, '2030-03-01 23:30:00-06');
        // Midnight sharp of 2 March is the start of that day, not the end of the previous one.
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 2000, '2030-03-02 00:00:00-06');
        // The first minute of the first day belongs to it.
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 4000, '2030-03-01 00:00:00-06');
        // The last minute of the day before does not.
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 8000, '2030-02-28 23:59:00-06');

        $this->report($this->puraNavaja, '2030-03-01', '2030-03-01')->assertOk()
            ->assertJsonPath('data.cobrado_en_linea.bruto', 5000)
            ->assertJsonPath('data.detalle.0.fecha', '2030-03-01');
        $this->report($this->puraNavaja, '2030-03-02', '2030-03-02')->assertOk()
            ->assertJsonPath('data.cobrado_en_linea.bruto', 2000);
    }

    /** A collaborator cannot open the report, and neither can a customer (CO-04); a person without a role there gets the usual 404 (UP-06). */
    public function test_only_the_owner_opens_the_report(): void
    {
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 5000, '2030-03-05 10:00:00-06');

        foreach (['collaborator', 'assigned_collaborator', 'customer', 'other_customer'] as $actor) {
            $this->sendAs($actor, 'GET', $this->url($this->puraNavaja, '2030-03-01', '2030-03-31'))
                ->assertForbidden()
                ->assertJsonPath('type', $this->problem('prohibido'));
        }

        foreach (['foreign_owner', 'foreign_collaborator'] as $actor) {
            $this->sendAs($actor, 'GET', $this->url($this->puraNavaja, '2030-03-01', '2030-03-31'))->assertNotFound();
        }

        $this->sendAs('anonymous', 'GET', $this->url($this->puraNavaja, '2030-03-01', '2030-03-31'))->assertUnauthorized();
        $this->sendAs('owner', 'GET', $this->url($this->puraNavaja, '2030-03-01', '2030-03-31'))->assertOk();
    }

    /** A range without data is not an error: it answers 200, every figure is zero and the detail is empty. */
    public function test_a_range_without_data_answers_zeros_and_an_empty_detail(): void
    {
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 5000, '2030-03-05 10:00:00-06');

        $this->report($this->puraNavaja, '2031-01-01', '2031-01-31')->assertOk()
            ->assertExactJson(['data' => [
                'desde' => '2031-01-01',
                'hasta' => '2031-01-31',
                'cobrado_en_linea' => ['bruto' => 0, 'reembolsado' => 0, 'neto' => 0],
                'bonos_vendidos' => ['total' => 0, 'cantidad' => 0],
                'servicios_atendidos' => ['total' => 0, 'cantidad' => 0],
                'detalle' => [],
            ]]);
    }

    /** Each owner adds up only their own barbershop, whatever the request says about another one (MT-02). */
    public function test_the_report_only_holds_the_data_of_the_barbershop_of_the_owner(): void
    {
        $this->insertOnlinePayment($this->puraNavaja, 'approved', 5000, '2030-03-05 10:00:00-06');
        $this->insertOnlinePayment($this->fadeLab, 'approved', 90000, '2030-03-05 10:00:00-06');
        $this->insertOnlinePayment($this->fadeLab, 'refunded', 40000, '2030-03-06 10:00:00-06', '2030-03-07 10:00:00-06');
        $beaMembership = (string) DB::table('memberships')->where('user_id', $this->bea)->value('id');
        $foreignVoucher = $this->insertPurchasedVoucher($this->fadeLab, $beaMembership, $this->insertVoucherDefinition($this->fadeLab, [$this->corte]));
        $this->activateVoucherOn($this->fadeLab, $foreignVoucher, '2030-03-05 12:00:00-06');
        $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $beaMembership, '2030-03-08 10:00:00-06', 'completed', 30);
        // Whatever the request says about another barbershop is ignored: the scope is the route.
        $noise = "&barberia={$this->fadeLab}&barbershop_id={$this->fadeLab}";

        $own = $this->sendAs('owner', 'GET', $this->url($this->puraNavaja, '2030-03-01', '2030-03-31').$noise)->assertOk();
        $own->assertJsonPath('data.cobrado_en_linea', ['bruto' => 5000, 'reembolsado' => 0, 'neto' => 5000])
            ->assertJsonPath('data.bonos_vendidos', ['total' => 0, 'cantidad' => 0])
            ->assertJsonPath('data.servicios_atendidos', ['total' => 0, 'cantidad' => 0]);
        $this->assertSame(['2030-03-05'], array_column($own->json('data.detalle'), 'fecha'));

        $this->sendAs('foreign_owner', 'GET', $this->url($this->fadeLab, '2030-03-01', '2030-03-31'))->assertOk()
            ->assertJsonPath('data.cobrado_en_linea', ['bruto' => 130000, 'reembolsado' => 40000, 'neto' => 90000])
            ->assertJsonPath('data.bonos_vendidos', ['total' => 30000, 'cantidad' => 1])
            ->assertJsonPath('data.servicios_atendidos', ['total' => 7000, 'cantidad' => 1]);
        // The owner of Pura Navaja cannot read the report of Fade Lab.
        $this->sendAs('owner', 'GET', $this->url($this->fadeLab, '2030-03-01', '2030-03-31'))->assertNotFound();
    }

    /**
     * A range that is not two real days in order, or longer than 366 days, is refused with 422 (decision of the issue: the cap keeps the sums light).
     *
     * @param  array<string, string>  $query
     */
    #[DataProvider('invalidRanges')]
    public function test_an_invalid_range_is_refused(array $query): void
    {
        $this->sendAs('owner', 'GET', "/api/v1/barberias/{$this->puraNavaja}/administracion/ingresos?".http_build_query($query))
            ->assertUnprocessable()
            ->assertJsonPath('type', $this->problem('datos-invalidos'));
    }

    /**
     * Ranges that must be refused.
     *
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidRanges(): iterable
    {
        yield 'without dates' => [[]];
        yield 'without the last day' => [['desde' => '2030-03-01']];
        yield 'reversed' => [['desde' => '2030-03-31', 'hasta' => '2030-03-01']];
        yield 'a day that does not exist' => [['desde' => '2030-02-30', 'hasta' => '2030-03-01']];
        yield 'another format' => [['desde' => '01/03/2030', 'hasta' => '31/03/2030']];
        yield 'one day over the cap' => [['desde' => '2028-01-01', 'hasta' => '2029-01-01']];
    }

    /** The cap is 366 days counting both ends: a whole leap year is accepted. */
    public function test_a_range_of_366_days_is_accepted(): void
    {
        $this->report($this->puraNavaja, '2028-01-01', '2028-12-31')->assertOk();
    }

    /**
     * Ask for the report as the owner of the barbershop.
     *
     * @return TestResponse<Response>
     */
    private function report(string $barbershopId, string $from, string $to): TestResponse
    {
        $actor = $barbershopId === $this->fadeLab ? 'foreign_owner' : 'owner';

        return $this->sendAs($actor, 'GET', $this->url($barbershopId, $from, $to));
    }

    /** Address of the report. */
    private function url(string $barbershopId, string $from, string $to): string
    {
        return "/api/v1/barberias/{$barbershopId}/administracion/ingresos?desde={$from}&hasta={$to}";
    }

    /** Type of a problem as the API publishes it. */
    private function problem(string $type): string
    {
        return rtrim((string) config('app.url'), '/').'/problemas/'.$type;
    }

    /** Insert a payment of a reservation of its own, with the instants it was created and last changed on. */
    private function insertOnlinePayment(string $barbershopId, string $status, int $amount, string $createdAt, ?string $updatedAt = null, bool $refundPending = false): string
    {
        [$collaborator, $service, $customer] = $barbershopId === $this->fadeLab
            ? [$this->mauricio, $this->corte, (string) DB::table('memberships')->where('user_id', $this->bea)->value('id')]
            : [$this->daniela, $this->degradado, $this->anaMembership];
        $day = str_pad((string) $this->nextDay++, 2, '0', STR_PAD_LEFT);
        $reservation = $this->insertReservation($barbershopId, $collaborator, $service, $customer, "2030-05-{$day}T10:00:00-06:00");
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($barbershopId);
        DB::table('payments')->insert([
            'id' => $id,
            'barbershop_id' => $barbershopId,
            'reservation_id' => $reservation,
            'provider' => 'onvo',
            'method' => 'card',
            'status' => $status,
            'amount' => $amount,
            'provider_payment_id' => in_array($status, ['approved', 'refunded'], true) ? 'pay_'.Str::random(12) : null,
            'refund_pending' => $refundPending,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ]);

        return $id;
    }

    /** Insert the approved payment of a voucher. */
    private function insertVoucherPayment(string $barbershopId, string $voucherId, int $amount, string $createdAt): void
    {
        $this->scopeQueriesTo($barbershopId);
        DB::table('payments')->insert([
            'id' => (string) Str::uuid7(),
            'barbershop_id' => $barbershopId,
            'purchased_voucher_id' => $voucherId,
            'provider' => 'onvo',
            'method' => 'card',
            'status' => 'approved',
            'amount' => $amount,
            'provider_payment_id' => 'pay_'.Str::random(12),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** Insert a completed reservation of Pura Navaja that started at the instant (7000 colones). */
    private function insertAttended(string $startsAt): string
    {
        return $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, $startsAt, 'completed');
    }

    /** Move the activation of an active voucher to an instant, with its expiry 90 days later. */
    private function activateVoucherOn(string $barbershopId, string $voucherId, string $activatedAt): void
    {
        $this->scopeQueriesTo($barbershopId);
        DB::table('purchased_vouchers')->where('id', $voucherId)->update([
            'activated_at' => $activatedAt,
            'expires_at' => (new DateTimeImmutable($activatedAt))->modify('+90 days')->format('Y-m-d H:i:sP'),
        ]);
    }
}
