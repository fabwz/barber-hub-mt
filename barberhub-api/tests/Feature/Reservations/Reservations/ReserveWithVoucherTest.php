<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Src\Shared\Domain\Contracts\Clock;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\TestCase;

/**
 * A customer books with a session of a voucher (RF-28): the reservation is confirmed with no deposit, the session is taken
 * in the same transaction and the balance never goes below zero (BO-03, BO-04, RE-07); outside the validity, for a service it
 * does not cover or with another barbershop's voucher it is refused (BO-06, BO-07); the one that expires first goes first
 * (BO-08); cancelling in time gives the session back and cancelling late leaves it used (CA-02, CA-03, CA-06). Pura Navaja
 * charges a 30 % deposit; today is Friday 1 March 2030, 8:00. The race of the last session with parallel connections is #133.
 */
final class ReserveWithVoucherTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private string $ana;

    private string $anaHere;

    private string $definition;

    /** Pura Navaja charges a deposit and Ana is its customer; a voucher definition covers "Degradado". */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo', 'payment_mode' => 'deposit', 'deposit_percentage' => 30]);
        [$this->ana, $this->anaHere] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
        $this->actAs($this->ana);
    }

    /** With a voucher the reservation is confirmed at once with no deposit, points at the voucher and takes one session in the record (RF-28, BO-04, RE-07). */
    public function test_a_voucher_confirms_the_reservation_without_a_deposit_and_takes_a_session(): void
    {
        $voucher = $this->voucher(5);

        $response = $this->book()->assertCreated()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.expira_en', null)
            ->assertJsonPath('data.monto_a_pagar', 0);

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame($voucher, DB::table('reservations')->where('id', $response->json('data.id'))->value('purchased_voucher_id'));
        $this->assertSame(4, $this->balance($voucher));
        $this->assertSame([['hold', -1, $response->json('data.id')]], $this->entries($voucher, 'hold'));
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame($this->balance($voucher), $this->recordSum($voucher));
    }

    /** Without `con_bono` the reservation is the usual one, temporary and waiting for its deposit, and no session is touched (RF-11). */
    public function test_a_reservation_without_the_flag_does_not_touch_the_voucher(): void
    {
        $voucher = $this->voucher(5);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->assertJsonPath('data.estado', 'temporal');

        $this->assertSame(5, $this->balance($voucher));
    }

    /** With one session the first request is confirmed and the next gets `saldo-insuficiente`: the balance ends at 0, never at -1, and nothing else remains (BO-03). */
    public function test_the_last_session_goes_to_one_reservation_and_the_other_is_refused(): void
    {
        $voucher = $this->voucher(1);

        $this->book()->assertCreated();
        $this->book('2030-03-05T14:00:00-06:00')
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/saldo-insuficiente')
            ->assertJsonPath('status', 409);

        $this->assertSame(0, $this->balance($voucher));
        $this->assertSame(0, $this->recordSum($voucher));
        $this->assertSame(1, $this->countIn('reservations', $this->puraNavaja));
    }

    /** A person without a paid voucher is refused with `saldo-insuficiente`, and a failed booking leaves no membership behind (BO-03, RF-11). */
    public function test_a_person_without_vouchers_gets_nothing_and_no_membership(): void
    {
        $luis = $this->insertUser('Luis Mora');
        $this->actAs($luis);

        $this->book()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/saldo-insuficiente');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('memberships')->where('user_id', $luis)->count());
        $this->assertSame(0, DB::table('reservations')->count());
    }

    /** An appointment after the end of the validity is refused with `bono-vencido` and no session is taken (BO-06, BO-07). */
    public function test_an_appointment_outside_the_validity_is_refused_as_expired(): void
    {
        $voucher = $this->voucher(5, validityDays: 2);

        $this->book()
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/bono-vencido')
            ->assertJsonPath('status', 409);

        $this->assertSame(5, $this->balance($voucher));
        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
    }

    /** A voucher that is valid today is expired for an appointment after its end: the date of the appointment decides (BO-06). */
    public function test_the_validity_is_judged_at_the_date_of_the_appointment(): void
    {
        $voucher = $this->voucher(5, validityDays: 5);

        $this->book('2030-03-05T10:00:00-06:00')->assertCreated();

        $this->book('2030-03-12T10:00:00-06:00')->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/bono-vencido');
        $this->assertSame(4, $this->balance($voucher));
    }

    /** With two applicable vouchers the one that expires first is used (BO-08). */
    public function test_the_voucher_that_expires_first_is_used_first(): void
    {
        $slow = $this->voucher(5, validityDays: 90);
        $fast = $this->voucher(5, validityDays: 30);

        $response = $this->book()->assertCreated();

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame($fast, DB::table('reservations')->where('id', $response->json('data.id'))->value('purchased_voucher_id'));
        $this->assertSame([4, 5], [$this->balance($fast), $this->balance($slow)]);
    }

    /** A service the voucher does not cover is refused, it is not charged as a difference and no session is taken (BO-06). */
    public function test_a_service_that_is_not_covered_does_not_use_the_voucher(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $beard = $this->insertService($this->puraNavaja, 'Barba', 30, 4000);
        $onlyBeard = $this->insertVoucherDefinition($this->puraNavaja, [$beard]);
        $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaHere, $onlyBeard);

        $this->book()
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/conflicto')
            ->assertJsonPath('status', 409);

        $this->assertSame(5, $this->balance($voucher));
        $this->assertSame(0, $this->countIn('reservations', $this->puraNavaja));
        $this->assertSame(0, $this->countIn('payments', $this->puraNavaja));
    }

    /** A voucher of another barbershop is never used, even if it has sessions and covers a service of the same name (MT-03, BO-07). */
    public function test_a_voucher_of_another_barbershop_is_not_used(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        [, $anaThere] = $this->insertCustomer($this->fadeLab, 'Ana Rojas');
        $elsewhere = $this->insertPurchasedVoucher($this->fadeLab, $anaThere, $this->insertVoucherDefinition($this->fadeLab, [$this->corte]));

        $this->book()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/saldo-insuficiente');

        $this->assertSame(5, $this->balance($elsewhere, $this->fadeLab));
    }

    /** Where the barbershop does not charge there is nothing to replace: 422 on the field and no session is used (RF-28). */
    public function test_a_voucher_is_rejected_where_the_barbershop_does_not_charge(): void
    {
        $this->scopeQueriesTo($this->fadeLab);
        [, $anaThere] = $this->insertCustomer($this->fadeLab, 'Ana Rojas');
        $voucher = $this->insertPurchasedVoucher($this->fadeLab, $anaThere, $this->insertVoucherDefinition($this->fadeLab, [$this->corte]));

        $response = $this->postJson($this->reservationsUrl($this->fadeLab), [...$this->booking($this->corte, $this->mauricio), 'con_bono' => true])
            ->assertUnprocessable()
            ->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos');

        $this->assertStringContainsString('con_bono', (string) json_encode($response->json('errores')));
        $this->assertSame(5, $this->balance($voucher, $this->fadeLab));
        $this->assertSame(0, $this->countIn('reservations', $this->fadeLab));
    }

    /** Cancelling in time gives the session back once, and repeating the cancellation does not give it back again (CA-02, BO-05, CA-06). */
    public function test_cancelling_in_time_gives_the_session_back_once(): void
    {
        $voucher = $this->voucher(5);
        $id = $this->book()->assertCreated()->json('data.id');
        $this->assertSame(4, $this->balance($voucher));

        $this->postJson($this->cancelUrl($id))->assertOk()->assertJsonPath('data.clasificacion', 'a_tiempo');
        $this->postJson($this->cancelUrl($id))->assertOk()->assertJsonPath('data.clasificacion', 'a_tiempo');

        $this->assertSame(5, $this->balance($voucher));
        $this->assertSame([['release', 1, $id]], $this->entries($voucher, 'release'));
        $this->assertSame(5, $this->recordSum($voucher));
    }

    /** Cancelling late leaves the session used: the balance stays down and the entry says it was consumed (CA-03, BO-05). */
    public function test_cancelling_late_leaves_the_session_used(): void
    {
        $voucher = $this->voucher(5);
        $id = $this->book()->assertCreated()->json('data.id');
        $this->clockAt('2030-03-05 08:00');

        $this->postJson($this->cancelUrl($id))->assertOk()->assertJsonPath('data.clasificacion', 'tardia');
        $this->postJson($this->cancelUrl($id))->assertOk();

        $this->assertSame(4, $this->balance($voucher));
        $this->assertSame([['consume', 0, $id]], $this->entries($voucher, 'consume'));
        $this->assertSame([], $this->entries($voucher, 'release'));
        $this->assertSame(4, $this->recordSum($voucher));
    }

    /** The cancellation of the barbershop gives the session back, even minutes before the appointment (BO-05, CA-04). */
    public function test_the_barbershop_cancelling_gives_the_session_back(): void
    {
        $voucher = $this->voucher(5);
        $id = $this->book()->assertCreated()->json('data.id');
        $this->clockAt('2030-03-05 09:30');
        $this->actAs($this->owner);

        $this->postJson($this->cancelUrl($id))->assertOk()->assertJsonPath('data.clasificacion', 'por_la_barberia');

        $this->assertSame(5, $this->balance($voucher));
        $this->assertSame([['release', 1, $id]], $this->entries($voucher, 'release'));
    }

    /** A reservation paid another way settles no voucher when it is cancelled (RF-17). */
    public function test_cancelling_a_reservation_without_voucher_settles_nothing(): void
    {
        $voucher = $this->voucher(5);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        $this->postJson($this->cancelUrl($id))->assertOk();

        $this->assertSame(5, $this->balance($voucher));
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('voucher_movements')->where('type', '!=', 'purchase')->count());
    }

    /**
     * Book Daniela's Tuesday space with a voucher.
     *
     * @return TestResponse<Response>
     */
    private function book(string $startsAt = self::TUESDAY_TEN): TestResponse
    {
        return $this->postJson($this->reservationsUrl($this->puraNavaja), [...$this->booking(startsAt: $startsAt), 'con_bono' => true]);
    }

    /** A voucher Ana bought, paid on 1 March 2030, and the identifier of it. */
    private function voucher(int $sessions, int $validityDays = 90): string
    {
        return $this->insertPurchasedVoucher($this->puraNavaja, $this->anaHere, $this->definition, 'active', $sessions, $validityDays);
    }

    private function cancelUrl(string $reservationId): string
    {
        return $this->reservationsUrl($this->puraNavaja)."/{$reservationId}/cancelacion";
    }

    private function clockAt(string $moment): void
    {
        $now = new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
        $this->app->instance(Clock::class, new class($now) implements Clock
        {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        });
    }

    /** The sessions the voucher has left, read from its row. */
    private function balance(string $voucher, ?string $barbershop = null): int
    {
        $this->scopeQueriesTo($barbershop ?? $this->puraNavaja);

        return (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions');
    }

    /** The sum of the entries of the record of the voucher (BO-09). */
    private function recordSum(string $voucher): int
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return (int) DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->sum('sessions_delta');
    }

    /**
     * The entries of a kind in the record of the voucher, as type, delta and reservation.
     *
     * @return list<array{string, int, string}>
     */
    private function entries(string $voucher, string $type): array
    {
        $this->scopeQueriesTo($this->puraNavaja);

        $entries = [];

        foreach (DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->where('type', $type)->get() as $row) {
            $entries[] = [(string) $row->type, (int) $row->sessions_delta, (string) $row->reservation_id];
        }

        return $entries;
    }
}
