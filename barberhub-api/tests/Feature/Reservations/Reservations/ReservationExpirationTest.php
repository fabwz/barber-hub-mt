<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Src\Reservations\Reservations\Application\UseCases\ConfirmReservation;
use Src\Reservations\Reservations\Domain\Exceptions\PaymentWindowExpiredException;
use Src\Reservations\Reservations\Domain\Exceptions\TransitionNotAllowedException;
use Src\Reservations\Reservations\Infrastructure\Persistence\Repositories\EloquentReservationRepository;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Shared\MutableClock;
use Tests\TestCase;

/**
 * A barbershop that charges leaves the reservation temporary for 10 minutes (RE-05, RE-07, PA-06). While the window is open
 * the space is held; once it closes the space is free again without any task having run, the reads show the reservation as
 * expired and the next booking of that barber marks it expired with the system as author (DI-05, RE-04, RE-09).
 */
final class ReservationExpirationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private const string TUESDAY = '2030-03-05';

    private string $ana;

    private string $luis;

    private MutableClock $clock;

    /** Seed the barbershops, two accounts and a deposit policy for Pura Navaja. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->clock = new MutableClock($this->moment('2030-03-01 08:00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->ana = $this->insertUser('Ana Rojas');
        $this->luis = $this->insertUser('Luis Mora');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'deposit', 'deposit_percentage' => 30, 'payment_gateway' => 'onvo']);
    }

    /** A barbershop without payment confirms at once and has no deadline (RE-07). */
    public function test_a_barbershop_without_payment_confirms_at_once(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'none', 'deposit_percentage' => null, 'payment_gateway' => null]);
        $this->actAs($this->ana);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
            ->assertCreated()
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.expira_en', null);
    }

    /** With a deposit the reservation stays temporary with a deadline 10 minutes ahead (RE-05, PA-06). */
    public function test_a_barbershop_that_charges_leaves_the_reservation_temporary_with_a_deadline(): void
    {
        $this->actAs($this->ana);

        $response = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
            ->assertCreated()
            ->assertJsonPath('data.estado', 'temporal')
            ->assertJsonPath('data.expira_en', '2030-03-01T08:10:00-06:00');

        $this->scopeQueriesTo($this->puraNavaja);
        $id = $response->json('data.id');
        $this->assertSame('temporary', DB::table('reservations')->where('id', $id)->value('status'));
        $this->assertNotNull(DB::table('reservations')->where('id', $id)->value('expires_at'));
        $this->assertSame(1, DB::table('reservation_status_history')->where('reservation_id', $id)->count());
    }

    /** While the window is open the space is held: nobody else books it and it is not offered (DI-05). */
    public function test_a_valid_temporary_reservation_holds_the_space(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();

        $this->setNow('2030-03-01 08:09:59');
        $this->assertNotContains('10:00', $this->offeredStarts());

        $this->actAs($this->luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
            ->assertStatus(409)
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'espacio-no-disponible'));
    }

    /** Past the deadline the space is offered again and the next booking marks the old one expired, authored by the system (RE-05, RE-09). */
    public function test_after_the_deadline_the_space_is_free_and_the_old_reservation_is_expired(): void
    {
        $this->actAs($this->ana);
        $old = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        $this->setNow('2030-03-01 08:10:00');
        $this->assertContains('10:00', $this->offeredStarts());

        $this->actAs($this->luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
            ->assertCreated()
            ->assertJsonPath('data.estado', 'temporal')
            ->assertJsonPath('data.expira_en', '2030-03-01T08:20:00-06:00');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('expired', DB::table('reservations')->where('id', $old)->value('status'));
        $this->assertNull(DB::table('reservations')->where('id', $old)->value('expires_at'));
        $history = DB::table('reservation_status_history')->where('reservation_id', $old);
        $this->assertSame(['temporary', 'expired'], $history->orderBy('changed_at')->pluck('to_status')->all());
        $this->assertNull($history->where('to_status', 'expired')->value('changed_by_user_id'));
    }

    /** The detail and the listings show an overdue temporary reservation as expired without writing anything (DI-05). */
    public function test_reads_show_an_overdue_reservation_as_expired_without_writing(): void
    {
        $this->actAs($this->ana);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        $url = $this->reservationsUrl($this->puraNavaja);

        $this->setNow('2030-03-01 08:09:59');
        $this->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('data.estado', 'temporal')->assertJsonPath('data.expira_en', '2030-03-01T08:10:00-06:00');

        $this->setNow('2030-03-01 08:10:00');
        $this->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('data.estado', 'expirada');
        $mine = $this->myReservationsUrl($this->puraNavaja);
        $this->getJson($mine)->assertOk()->assertJsonPath('data.0.estado', 'expirada');
        $this->assertSame([$id], $this->getJson("{$mine}?estado=expirada")->assertOk()->json('data.*.id'));
        $this->assertSame([], $this->getJson("{$mine}?estado=temporal")->assertOk()->json('data'));

        $this->actAs($this->owner);
        $this->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('data.estado', 'expirada');
        $this->assertSame([$id], $this->getJson("{$url}?estado=expirada")->assertOk()->json('data.*.id'));
        $this->assertSame([], $this->getJson("{$url}?estado=temporal")->assertOk()->json('data'));

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('temporary', DB::table('reservations')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('reservation_status_history')->where('reservation_id', $id)->count());
    }

    /** The customer can still cancel a temporary reservation that is within its window (RE-04). */
    public function test_the_customer_cancels_a_temporary_reservation(): void
    {
        $this->actAs($this->ana);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada_por_cliente')
            ->assertJsonPath('data.expira_en', null);
    }

    /** Rescheduling a confirmed reservation into the space of an overdue temporary one succeeds (RE-05, DI-05). */
    public function test_rescheduling_into_the_space_of_an_overdue_reservation_succeeds(): void
    {
        $this->actAs($this->ana);
        $overdue = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_mode' => 'none', 'deposit_percentage' => null, 'payment_gateway' => null]);

        $this->actAs($this->luis);
        $mine = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated()->json('data.id');

        $this->setNow('2030-03-01 08:30:00');
        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$mine}", ['inicio' => self::TUESDAY_TEN])
            ->assertOk()
            ->assertJsonPath('data.inicio', '2030-03-05T10:00:00-06:00');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('expired', DB::table('reservations')->where('id', $overdue)->value('status'));
    }

    /** The payments module confirms a reservation whose window is open, authored by the system (PA-06, RE-09). */
    public function test_confirming_within_the_window_confirms_with_the_system_as_author(): void
    {
        $this->actAs($this->ana);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        $reservation = $this->confirmReservation()->execute($id, $this->moment('2030-03-01 08:09:59'));

        $this->assertSame('confirmed', $reservation->status->value);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('confirmed', DB::table('reservations')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('reservations')->where('id', $id)->value('expires_at'));
        $confirmation = DB::table('reservation_status_history')->where('reservation_id', $id)->where('to_status', 'confirmed');
        $this->assertSame('temporary', $confirmation->value('from_status'));
        $this->assertNull($confirmation->value('changed_by_user_id'));
    }

    /** A reservation whose window closed is not confirmed, even if nobody marked it expired, and it answers 409 `conflicto` (RE-05). */
    public function test_confirming_after_the_window_fails_and_changes_nothing(): void
    {
        $this->actAs($this->ana);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');

        try {
            $this->confirmReservation()->execute($id, $this->moment('2030-03-01 08:10:00'));
            $this->fail('A reservation past its window was confirmed.');
        } catch (PaymentWindowExpiredException $exception) {
            $this->assertSame('reservations.payment_window_expired', $exception->translationKey());
            $this->assertSame('conflicto', $exception->rule());
        }

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('temporary', DB::table('reservations')->where('id', $id)->value('status'));
    }

    /** A reservation that is no longer temporary is not confirmed again (RE-04). */
    public function test_confirming_a_reservation_that_is_not_temporary_fails(): void
    {
        $this->actAs($this->ana);
        $id = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->json('data.id');
        $this->postJson($this->reservationsUrl($this->puraNavaja)."/{$id}/cancelacion")->assertOk();

        $this->expectException(TransitionNotAllowedException::class);

        $this->confirmReservation()->execute($id, $this->moment('2030-03-01 08:05:00'));
    }

    /** The use case of the payments module, working on Pura Navaja. */
    private function confirmReservation(): ConfirmReservation
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return new ConfirmReservation(new EloquentReservationRepository(new FixedBarbershopContext(new BarbershopId($this->puraNavaja))));
    }

    /** Move the clock of the application. */
    private function setNow(string $moment): void
    {
        $this->clock->moveTo($this->moment($moment));
    }

    /** An instant in the time zone of Costa Rica. */
    private function moment(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /**
     * The hours Daniela offers for Degradado on Tuesday.
     *
     * @return list<string>
     */
    private function offeredStarts(): array
    {
        $query = http_build_query(['servicio' => $this->degradado, 'fecha' => self::TUESDAY, 'barbero' => $this->daniela]);
        $spaces = $this->getJson("/api/v1/barberias/{$this->puraNavaja}/disponibilidad?{$query}")->assertOk()->json('data.barberos.0.espacios');

        return array_values(array_map(fn (array $space): string => substr($space['inicio'], 11, 5), $spaces));
    }
}
