<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\Reservations;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Application\Contracts\FreeSlots;
use Src\Reservations\Reservations\Infrastructure\Availability\AvailabilityFreeSlots;
use Src\Shared\Domain\Contracts\Clock;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\TestCase;

/**
 * A customer or the owner moves a confirmed reservation to another space (RF-15): atomically, revalidating the new space
 * without counting the reservation itself, within the deadline for the customer and at most twice, and leaving the
 * original untouched when the new space is taken (RE-08, CA-01, CA-05, CA-07, DI-01, RE-01, UP-06). The reservation of
 * Ana is on Tuesday 5 March 2030 at 10:00 with Daniela and a deadline of 4 hours; the clock starts on Friday 1 March, 8:00.
 */
final class RescheduleReservationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $anaRole;

    private string $luis;

    private string $luisRole;

    private string $marta;

    private string $danielaUser;

    private string $reservation;

    private string $luisReservation;

    private string $fadeLabReservation;

    /** Ana has her reservation with Daniela at 10:00; Luis has one with Daniela at 16:00; Fade Lab has one of its own. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana, $this->anaRole] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [$this->luis, $this->luisRole] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        [$this->marta] = $this->insertCustomer($this->puraNavaja, 'Marta Vega');
        $this->danielaUser = (string) DB::table('collaborators')->where('id', $this->daniela)->value('user_id');

        $this->reservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaRole, self::TUESDAY_TEN);
        $this->luisReservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->luisRole, '2030-03-05T16:00:00-06:00');
        DB::table('reservations')->update(['cancellation_deadline_hours' => 4]);

        $this->scopeQueriesTo($this->fadeLab);
        $anaThere = (string) Str::uuid7();
        DB::table('memberships')->insert(['id' => $anaThere, 'user_id' => $this->ana, 'barbershop_id' => $this->fadeLab, 'role' => 'customer']);
        $this->fadeLabReservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $anaThere, self::TUESDAY_TEN, minutes: 30);
    }

    /** With a deadline of 4 hours, rescheduling 5 hours before the original start works; the copies of the service stay (CA-01, SV-03, CA-05). */
    public function test_five_hours_before_it_works_and_keeps_the_copies(): void
    {
        $this->clockAt('2030-03-05 05:00');
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->reservation)
            ->assertJsonPath('data.estado', 'confirmada')
            ->assertJsonPath('data.barbero', $this->daniela)
            ->assertJsonPath('data.inicio', '2030-03-05T14:00:00-06:00')
            ->assertJsonPath('data.fin', '2030-03-05T14:45:00-06:00')
            ->assertJsonPath('data.precio', 7000)
            ->assertJsonPath('data.duracion_minutos', 45)
            ->assertJsonPath('data.plazo_cancelacion_horas', 4);

        $row = $this->rowOf($this->reservation);
        $this->assertSame('2030-03-05 14:00:00-06', $row['starts_at']);
        $this->assertSame('2030-03-05 14:45:00-06', $row['ends_at']);
        $this->assertSame('2030-03-05 14:50:00-06', $row['blocked_until']);
        $this->assertSame(1, $row['reschedule_count']);
        $this->assertSame([7000, 45, 4, 'confirmed'], [$row['price'], $row['duration_minutes'], $row['cancellation_deadline_hours'], $row['status']]);
    }

    /** Rescheduling 3 hours before the original start is rejected with `plazo-vencido` and the reservation stays (CA-01). */
    public function test_three_hours_before_it_is_rejected_with_the_deadline_error(): void
    {
        $this->clockAt('2030-03-05 07:00');
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/plazo-vencido');

        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
    }

    /** The deadline is measured against the original start, not the new one: moving to a far date 3 hours before still fails (CA-01). */
    public function test_the_deadline_looks_at_the_original_start(): void
    {
        $this->clockAt('2030-03-05 07:00');
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-12T10:00:00-06:00'])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/plazo-vencido');
    }

    /** The owner reschedules with no deadline, even minutes before the start, and the entry carries the owner (CA-07, RE-09). */
    public function test_the_owner_reschedules_without_a_deadline(): void
    {
        $this->clockAt('2030-03-05 09:30');
        $this->actAs($this->owner);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])
            ->assertOk()
            ->assertJsonPath('data.inicio', '2030-03-05T14:00:00-06:00');

        $this->assertSame([['confirmed', 'confirmed', $this->owner]], $this->entriesOf($this->reservation));
        $this->assertSame(1, $this->rowOf($this->reservation)['reschedule_count']);
    }

    /** The count rises with each reschedule and the third one is rejected with a conflict, whoever asks (CA-05). */
    public function test_the_third_reschedule_is_rejected(): void
    {
        $this->actAs($this->ana);

        foreach ([['2030-03-05T11:00:00-06:00', 1], ['2030-03-05T14:00:00-06:00', 2]] as [$start, $count]) {
            $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => $start])->assertOk();
            $this->assertSame($count, $this->rowOf($this->reservation)['reschedule_count']);
        }

        foreach ([$this->ana, $this->owner] as $person) {
            $this->actAs($person);
            $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T15:00:00-06:00'])
                ->assertConflict()
                ->assertJsonPath('type', 'http://localhost/problemas/conflicto');
        }

        $row = $this->rowOf($this->reservation);
        $this->assertSame(2, $row['reschedule_count']);
        $this->assertSame('2030-03-05 14:00:00-06', $row['starts_at']);
        $this->assertCount(2, $this->entriesOf($this->reservation));
    }

    /** Moving the reservation 15 minutes overlaps its own old space, and it works: it does not count itself (RE-08, RE-01, RE-06). */
    public function test_moving_it_fifteen_minutes_ignores_itself(): void
    {
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T10:15:00-06:00'])
            ->assertOk()
            ->assertJsonPath('data.inicio', '2030-03-05T10:15:00-06:00')
            ->assertJsonPath('data.fin', '2030-03-05T11:00:00-06:00');

        $this->assertSame('2030-03-05 10:15:00-06', $this->rowOf($this->reservation)['starts_at']);
    }

    /** Changing the barber moves the reservation to the space of the other one, and the old space is free for someone else (RF-15, CA-07). */
    public function test_the_barber_can_change_and_the_old_space_is_freed(): void
    {
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T10:00:00-06:00', 'barbero' => $this->kevin])
            ->assertOk()
            ->assertJsonPath('data.barbero', $this->kevin);

        $this->assertSame($this->kevin, $this->rowOf($this->reservation)['collaborator_id']);

        $this->actAs($this->marta);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->assertJsonPath('data.barbero', $this->daniela);
    }

    /** The original space is free as soon as the reservation moves, so another person books it and the moved one keeps the new one (RE-08). */
    public function test_the_original_space_can_be_booked_by_another_person(): void
    {
        $this->actAs($this->marta);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->actAs($this->ana);
        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])->assertOk();

        $this->actAs($this->marta);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertConflict();
    }

    /**
     * Two customers want the same new space: the first gets it, the second gets `espacio-no-disponible` with alternatives of the
     * same barber, and the reservation of the second stays as it was and keeps its own space taken (RE-08, RE-01, RE-02).
     */
    public function test_the_loser_of_a_contested_space_keeps_the_original_reservation(): void
    {
        $this->actAs($this->ana);
        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])->assertOk();

        $this->actAs($this->luis);
        $response = $this->patchJson($this->reservationUrl($this->luisReservation), ['inicio' => '2030-03-05T14:00:00-06:00'])
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $alternatives = $response->json('alternativas');
        $this->assertCount(3, $alternatives);
        $this->assertSame([$this->daniela], array_values(array_unique(array_column($alternatives, 'barbero'))));
        $this->assertNotContains('2030-03-05T14:00:00-06:00', array_column($alternatives, 'inicio'));

        $this->assertUntouched($this->luisReservation, '2030-03-05 16:00:00-06');
        $this->assertSame([], $this->entriesOf($this->luisReservation));

        $this->actAs($this->marta);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T16:00:00-06:00'))
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');
    }

    /** Moving to the space of another reservation is rejected and the original is intact, with no history entry (RE-08). */
    public function test_a_taken_space_leaves_the_original_intact(): void
    {
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T16:15:00-06:00'])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
        $this->assertSame([], $this->entriesOf($this->reservation));
    }

    /**
     * The second barrier: when the calculation says free but the database already has the collision (here the check always
     * says free), the exclusion rejects the update, the error is the same with its alternatives and nothing was written
     * (ADR 0009, RE-08).
     */
    public function test_the_exclusion_of_the_database_is_the_second_barrier(): void
    {
        $real = $this->app->make(AvailabilityFreeSlots::class);
        $this->app->bind(FreeSlots::class, fn (): FreeSlots => new class($real) implements FreeSlots
        {
            public function __construct(private readonly FreeSlots $real) {}

            public function isFree(string $serviceId, string $collaboratorId, DateTimeImmutable $startsAt, DateTimeImmutable $now, ?string $ignoringReservationId = null): bool
            {
                return true;
            }

            public function alternatives(string $serviceId, string $collaboratorId, DateTimeImmutable $requested, DateTimeImmutable $now, ?string $ignoringReservationId = null): array
            {
                return $this->real->alternatives($serviceId, $collaboratorId, $requested, $now, $ignoringReservationId);
            }
        });
        $this->actAs($this->ana);

        $response = $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T16:15:00-06:00'])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->assertCount(3, $response->json('alternativas'));
        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
        $this->assertSame([], $this->entriesOf($this->reservation));
    }

    /** The customer cannot be in two confirmed reservations at once: moving onto another of their own is a conflict, and the reservation stays (RE-06). */
    public function test_a_customer_cannot_move_onto_their_own_other_reservation(): void
    {
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaRole, '2030-03-05T11:00:00-06:00');
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T11:00:00-06:00'])
            ->assertConflict()
            ->assertJsonPath('type', 'http://localhost/problemas/conflicto');

        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
    }

    /** A collaborator reschedules nothing, not even a reservation of their own agenda: 403 and the reservation stays (RF-15). */
    public function test_a_collaborator_gets_403(): void
    {
        $this->actAs($this->danielaUser);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])
            ->assertForbidden()
            ->assertJsonPath('type', 'http://localhost/problemas/prohibido');

        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
    }

    /** Another customer, a missing reservation, one of another barbershop and a stranger get the same 404 (UP-06, MT-03). */
    public function test_foreign_reservations_answer_the_same_404(): void
    {
        $this->actAs($this->luis);
        $missing = $this->patchJson($this->reservationUrl((string) Str::uuid7()), ['inicio' => '2030-03-05T14:00:00-06:00'])->assertNotFound();

        foreach ([$this->reservation, $this->fadeLabReservation] as $foreign) {
            $response = $this->patchJson($this->reservationUrl($foreign), ['inicio' => '2030-03-05T14:00:00-06:00'])->assertNotFound();
            $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($response->json()));
        }

        $this->actAs($this->owner);
        $this->patchJson($this->reservationUrl($this->fadeLabReservation), ['inicio' => '2030-03-05T14:00:00-06:00'])->assertNotFound();

        $stranger = $this->insertUser('Persona Nueva');
        $this->actAs($stranger);
        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])->assertNotFound();
        $this->assertSame(0, DB::table('memberships')->where('user_id', $stranger)->count());

        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
        $this->assertSame('2030-03-05 10:00:00-06', $this->rowOf($this->fadeLabReservation, $this->fadeLab)['starts_at']);
    }

    /** A collaborator of another barbershop is as missing as an unknown one and the reservation stays (MT-03). */
    public function test_a_barber_of_another_barbershop_is_not_found(): void
    {
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00', 'barbero' => $this->mauricio])
            ->assertNotFound()
            ->assertJsonPath('type', 'http://localhost/problemas/no-encontrado');

        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
    }

    /** Only a confirmed reservation moves: a cancelled, completed, missed or expired one answers a conflict and stays (RE-04). */
    public function test_a_reservation_that_is_not_confirmed_is_a_conflict(): void
    {
        $this->actAs($this->owner);

        foreach (['cancelled_by_customer', 'cancelled_by_barbershop', 'completed', 'no_show', 'expired', 'temporary'] as $status) {
            $this->scopeQueriesTo($this->puraNavaja);
            DB::table('reservations')->where('id', $this->reservation)->update(['status' => $status]);

            $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])
                ->assertConflict()
                ->assertJsonPath('type', 'http://localhost/problemas/conflicto');

            $row = $this->rowOf($this->reservation);
            $this->assertSame($status, $row['status']);
            $this->assertSame('2030-03-05 10:00:00-06', $row['starts_at']);
        }
    }

    /** The new start is required and must be an ISO 8601 moment with offset; the barber must be a UUID (RF-15). */
    public function test_invalid_data_is_rejected_with_the_field(): void
    {
        $this->actAs($this->ana);

        $this->patchJson($this->reservationUrl($this->reservation), [])->assertUnprocessable()->assertJsonPath('errores.inicio.0', fn (string $message): bool => $message !== '');
        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => 'mañana'])->assertUnprocessable()->assertJsonValidationErrorFor('inicio', 'errores');
        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00', 'barbero' => 'Daniela'])->assertUnprocessable()->assertJsonValidationErrorFor('barbero', 'errores');

        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
    }

    /** Without a session the reschedule answers 401. */
    public function test_without_a_session_the_reschedule_answers_401(): void
    {
        $this->patchJson($this->reservationUrl($this->reservation), ['inicio' => '2030-03-05T14:00:00-06:00'])->assertUnauthorized();
        $this->assertUntouched($this->reservation, '2030-03-05 10:00:00-06');
    }

    private function reservationUrl(string $reservationId): string
    {
        return $this->reservationsUrl($this->puraNavaja)."/{$reservationId}";
    }

    /** Replace the clock with one that tells the moment, in Costa Rica. */
    private function clockAt(string $moment): void
    {
        $this->app->instance(Clock::class, new class($moment) implements Clock
        {
            public function __construct(private readonly string $moment) {}

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->moment, new DateTimeZone('America/Costa_Rica'));
            }
        });
    }

    /**
     * The stored row of a reservation.
     *
     * @return array<string, mixed>
     */
    private function rowOf(string $reservationId, ?string $barbershopId = null): array
    {
        $this->scopeQueriesTo($barbershopId ?? $this->puraNavaja);

        return (array) (DB::table('reservations')->where('id', $reservationId)->first() ?? $this->fail('The reservation is not stored.'));
    }

    /** The reservation is confirmed, never rescheduled, at its original start, and has the same copies. */
    private function assertUntouched(string $reservationId, string $startsAt): void
    {
        $row = $this->rowOf($reservationId);

        $this->assertSame('confirmed', $row['status']);
        $this->assertSame(0, $row['reschedule_count']);
        $this->assertSame($startsAt, $row['starts_at']);
    }

    /**
     * The history entries of a reservation; the seed writes none, so these are the changes the reschedule made.
     *
     * @return list<array{string|null, string, string|null}>
     */
    private function entriesOf(string $reservationId): array
    {
        $this->scopeQueriesTo($this->puraNavaja);

        $entries = DB::table('reservation_status_history')
            ->where('reservation_id', $reservationId)
            ->orderBy('changed_at')
            ->orderBy('id')
            ->get();

        return array_values($entries->map(fn (object $entry): array => [$entry->from_status, $entry->to_status, $entry->changed_by_user_id])->all());
    }
}
