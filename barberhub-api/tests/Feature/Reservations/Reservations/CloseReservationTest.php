<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\TestCase;

/**
 * The collaborator closes their own reservations and the owner any of the barbershop, as attended or as an absence, only after
 * the start and, for an absence, after the tolerance of 10 minutes (RF-18, CO-05, RE-04, RE-09, CA-03, UP-06). The reservation
 * of Daniela starts on Tuesday 5 March 2030 at 10:00; the clock of each test says where "now" is.
 */
final class CloseReservationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsVouchers;

    private MutableClock $clock;

    private string $ana;

    private string $anaHere;

    private string $danielaUser;

    private string $kevinUser;

    private string $reservation;

    private string $temporary;

    private string $cancelled;

    private string $fadeLabReservation;

    private string $fadeLabOwner;

    /** Ana has a confirmed reservation with Daniela on 5 March at 10:00, a temporary one and a cancelled one; Fade Lab has one of its own. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->clock = new MutableClock($this->at('2030-03-05 10:30'));
        $this->app->instance(Clock::class, $this->clock);

        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana, $this->anaHere] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        $this->danielaUser = (string) DB::table('collaborators')->where('id', $this->daniela)->value('user_id');
        $this->kevinUser = (string) DB::table('collaborators')->where('id', $this->kevin)->value('user_id');
        $this->reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaHere, '2030-03-05T10:00:00-06:00');
        $this->temporary = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaHere, '2030-03-05T15:00:00-06:00', 'temporary');
        $this->cancelled = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaHere, '2030-03-05T16:00:00-06:00', 'cancelled_by_customer');

        $this->scopeQueriesTo($this->fadeLab);
        $this->fadeLabOwner = $this->insertUser('Dueño de Fade Lab');
        $this->grantRole($this->fadeLabOwner, $this->fadeLab, 'owner');
        $anaThere = (string) Str::uuid7();
        DB::table('memberships')->insert(['id' => $anaThere, 'user_id' => $this->ana, 'barbershop_id' => $this->fadeLab, 'role' => 'customer']);
        $this->fadeLabReservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $anaThere, '2030-03-05T10:00:00-06:00', minutes: 30);
    }

    /** The collaborator of the agenda marks it attended after the start: it is "completada" and the history keeps who and when (RF-18, RE-09). */
    public function test_the_collaborator_marks_their_reservation_attended(): void
    {
        $this->actAs($this->danielaUser);

        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->reservation)
            ->assertJsonPath('data.estado', 'completada');

        $this->assertSame('completed', $this->statusOf($this->reservation));
        $this->assertSame([['confirmed', 'completed', $this->danielaUser]], $this->entriesOf($this->reservation));
        $this->assertSame('2030-03-05 10:30:00', $this->changedAtOf($this->reservation));
    }

    /** The owner closes any reservation of the barbershop, an absence included. */
    public function test_the_owner_marks_any_reservation(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])->assertOk()->assertJsonPath('data.estado', 'ausente');

        $this->assertSame('no_show', $this->statusOf($this->reservation));
        $this->assertSame([['confirmed', 'no_show', $this->owner]], $this->entriesOf($this->reservation));
    }

    /** Before the start neither outcome is accepted, and the detail says from when it can be marked (CO-05). */
    public function test_marking_before_the_start_is_refused_with_the_time(): void
    {
        $this->clock->moveTo($this->at('2030-03-05 09:59:59'));
        $this->actAs($this->danielaUser);

        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/conflicto')
            ->assertJsonPath('detail', 'La reserva se puede marcar como atendida a partir del 05/03/2030 a las 10:00, la hora de su inicio.');
        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])
            ->assertConflict()
            ->assertJsonPath('detail', 'La reserva se puede marcar como ausente a partir del 05/03/2030 a las 10:10, 10 minutos después de su inicio.');

        $this->assertSame('confirmed', $this->statusOf($this->reservation));
        $this->assertSame([], $this->entriesOf($this->reservation));
    }

    /** An absence is refused before the 10 minutes of tolerance and accepted at the tenth; attended is accepted from the start (CO-05). */
    public function test_the_tolerance_of_the_absence_is_ten_minutes(): void
    {
        $this->actAs($this->danielaUser);

        $this->clock->moveTo($this->at('2030-03-05 10:09:59'));
        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])->assertConflict();
        $this->assertSame('confirmed', $this->statusOf($this->reservation));

        $this->clock->moveTo($this->at('2030-03-05 10:10'));
        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])->assertOk()->assertJsonPath('data.estado', 'ausente');

        $atTheStart = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaHere, '2030-03-05T11:00:00-06:00');
        $this->actAs($this->kevinUser);
        $this->clock->moveTo($this->at('2030-03-05 10:59:59'));
        $this->postJson($this->closeUrl($atTheStart), ['estado' => 'atendida'])->assertConflict();
        $this->clock->moveTo($this->at('2030-03-05 11:00'));
        $this->postJson($this->closeUrl($atTheStart), ['estado' => 'atendida'])->assertOk();
    }

    /** A final status does not change: the other outcome is a conflict and the first one stays (RE-04). */
    public function test_a_final_status_does_not_change(): void
    {
        $this->actAs($this->owner);
        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])->assertOk();

        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');

        $this->assertSame('completed', $this->statusOf($this->reservation));
        $this->assertCount(1, $this->entriesOf($this->reservation));
    }

    /** Repeating the same outcome answers 200 and adds nothing to the history (CA-06). */
    public function test_repeating_the_operation_changes_nothing(): void
    {
        $this->actAs($this->danielaUser);
        $first = $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])->assertOk();
        $entries = $this->entriesOf($this->reservation);

        $this->clock->moveTo($this->at('2030-03-05 12:00'));
        $second = $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame($entries, $this->entriesOf($this->reservation));
        $this->assertSame('2030-03-05 10:30:00', $this->changedAtOf($this->reservation));
    }

    /** A temporary, a cancelled or an expired reservation cannot be closed (RE-04). */
    public function test_only_a_confirmed_reservation_can_be_closed(): void
    {
        $this->actAs($this->owner);
        $this->clock->moveTo($this->at('2030-03-05 18:00'));

        foreach ([$this->temporary, $this->cancelled] as $id) {
            $this->postJson($this->closeUrl($id), ['estado' => 'atendida'])->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');
            $this->assertSame([], $this->entriesOf($id));
        }

        $this->assertSame('temporary', $this->statusOf($this->temporary));
        $this->assertSame('cancelled_by_customer', $this->statusOf($this->cancelled));
    }

    /** A collaborator cannot close a reservation of another agenda: it reads as a missing one, with the same body (CO-05, UP-06). */
    public function test_another_collaborator_gets_404(): void
    {
        $this->actAs($this->kevinUser);

        $missing = $this->postJson($this->closeUrl((string) Str::uuid7()), ['estado' => 'atendida'])->assertNotFound();
        $other = $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])->assertNotFound();

        $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($other->json()));
        $this->assertSame('confirmed', $this->statusOf($this->reservation));
    }

    /** A customer, even the one of the reservation, gets 403 and nothing changes. */
    public function test_a_customer_gets_403(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');

        $this->assertSame('confirmed', $this->statusOf($this->reservation));
    }

    /** The owner of another barbershop, and a reservation of another barbershop under this URL, get 404 (UP-06, MT-03). */
    public function test_another_barbershop_gets_404(): void
    {
        $this->actAs($this->fadeLabOwner);
        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])->assertNotFound();

        $this->actAs($this->owner);
        $this->postJson($this->closeUrl($this->fadeLabReservation), ['estado' => 'atendida'])->assertNotFound();

        $this->assertSame('confirmed', $this->statusOf($this->reservation));
        $this->assertSame('confirmed', $this->statusOf($this->fadeLabReservation, $this->fadeLab));
    }

    /** Without a session it answers 401. */
    public function test_without_a_session_it_answers_401(): void
    {
        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'atendida'])->assertUnauthorized();

        $this->assertSame('confirmed', $this->statusOf($this->reservation));
    }

    /** An outcome that is neither of the two is a validation error. */
    public function test_an_unknown_outcome_is_a_validation_error(): void
    {
        $this->actAs($this->owner);

        foreach ([[], ['estado' => 'cancelada'], ['estado' => 'completada']] as $body) {
            $this->postJson($this->closeUrl($this->reservation), $body)->assertUnprocessable()->assertJsonPath('type', 'http://localhost/problemas/datos-invalidos');
        }

        $this->assertSame('confirmed', $this->statusOf($this->reservation));
    }

    /** An absence leaves the session of the voucher used, once, and the balance does not come back (CA-03, BO-05). */
    public function test_an_absence_consumes_the_session_of_the_voucher_once(): void
    {
        $voucher = $this->payWithVoucher($this->reservation);
        $this->actAs($this->owner);

        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])->assertOk();
        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])->assertOk();

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([['consume', 0, $this->reservation]], $this->entries($voucher, 'consume'));
        $this->assertSame(4, (int) DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->sum('sessions_delta'));
        $this->assertSame(4, (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions'));
        $this->assertSame(0, DB::table('voucher_movements')->where('purchased_voucher_id', $voucher)->where('type', 'release')->count());
    }

    /** Closing a reservation that is refused consumes nothing. */
    public function test_a_refused_closing_does_not_touch_the_voucher(): void
    {
        $voucher = $this->payWithVoucher($this->reservation);
        $this->clock->moveTo($this->at('2030-03-05 10:05'));
        $this->actAs($this->owner);

        $this->postJson($this->closeUrl($this->reservation), ['estado' => 'ausente'])->assertConflict();

        $this->assertSame([], $this->entries($voucher, 'consume'));
    }

    /**
     * Pay a reservation with a session of a new voucher of Ana: the session is held, as the booking does.
     *
     * @return string The voucher.
     */
    private function payWithVoucher(string $reservationId): string
    {
        $definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);
        $voucher = $this->insertPurchasedVoucher($this->puraNavaja, $this->anaHere, $definition);
        $this->insertVoucherMovement($this->puraNavaja, $voucher, 'hold', -1, $reservationId);
        DB::table('purchased_vouchers')->where('id', $voucher)->update(['remaining_sessions' => 4]);
        DB::table('reservations')->where('id', $reservationId)->update(['purchased_voucher_id' => $voucher]);

        return $voucher;
    }

    private function closeUrl(string $reservationId): string
    {
        return $this->reservationsUrl($this->puraNavaja)."/{$reservationId}/cierre";
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /** The stored status of a reservation. */
    private function statusOf(string $reservationId, ?string $barbershopId = null): string
    {
        $this->scopeQueriesTo($barbershopId ?? $this->puraNavaja);

        return (string) DB::table('reservations')->where('id', $reservationId)->value('status');
    }

    /**
     * The history entries of a reservation; the seed writes none, so these are the changes the closing made.
     *
     * @return list<array{string|null, string, string|null}>
     */
    private function entriesOf(string $reservationId): array
    {
        $this->scopeQueriesTo($this->puraNavaja);

        $entries = DB::table('reservation_status_history')->where('reservation_id', $reservationId)->orderBy('changed_at')->orderBy('id')->get();

        return array_values($entries->map(fn (object $entry): array => [$entry->from_status, $entry->to_status, $entry->changed_by_user_id])->all());
    }

    /** The instant of the last history entry of a reservation, in Costa Rica. */
    private function changedAtOf(string $reservationId): string
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return $this->at((string) DB::table('reservation_status_history')->where('reservation_id', $reservationId)->orderByDesc('changed_at')->value('changed_at'))->format('Y-m-d H:i:s');
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
