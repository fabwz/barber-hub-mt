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
 * A customer cancels their own reservation and the owner any of the barbershop (RF-16): the status changes and the space
 * is free in the same operation, the cancellation is classified, repeating it changes nothing and nobody reaches a
 * reservation that is not theirs (CA-01, CA-04, CA-06, CA-07, RE-04, RE-09, UP-06). Today is Friday 1 March 2030, 8:00.
 */
final class CancelReservationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;

    private string $ana;

    private string $luis;

    private string $danielaUser;

    private string $onTime;

    private string $late;

    private string $completed;

    private string $noShow;

    private string $temporary;

    private string $luisReservation;

    private string $fadeLabReservation;

    /**
     * Ana has a reservation four days ahead (on time, deadline of 24 hours), one 12 hours ahead (late), one completed, one
     * where she did not show up and a temporary one. Luis has one. Fade Lab has one of its own.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        $this->scopeQueriesTo($this->puraNavaja);
        [$this->ana, $anaHere] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [$this->luis, $luisHere] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $this->danielaUser = (string) DB::table('collaborators')->where('id', $this->daniela)->value('user_id');

        $this->onTime = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $anaHere, self::TUESDAY_TEN);
        $this->late = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $anaHere, '2030-03-01T20:00:00-06:00');
        $this->completed = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $anaHere, '2030-02-20T10:00:00-06:00', 'completed');
        $this->noShow = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $anaHere, '2030-02-21T10:00:00-06:00', 'no_show');
        $this->temporary = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $anaHere, '2030-03-08T10:00:00-06:00', 'temporary');
        $this->luisReservation = $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $luisHere, '2030-03-09T10:00:00-06:00');

        $this->scopeQueriesTo($this->fadeLab);
        $anaThere = (string) Str::uuid7();
        DB::table('memberships')->insert(['id' => $anaThere, 'user_id' => $this->ana, 'barbershop_id' => $this->fadeLab, 'role' => 'customer']);
        $this->fadeLabReservation = $this->insertReservation($this->fadeLab, $this->mauricio, $this->corte, $anaThere, self::TUESDAY_TEN, minutes: 30);
    }

    /** A customer who cancels before the deadline gets "cancelada por el cliente" classified on time, and leaves their entry in the history (RF-16, CA-01, RE-09). */
    public function test_a_customer_cancels_on_time(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->cancelUrl($this->onTime))
            ->assertOk()
            ->assertJsonPath('data.id', $this->onTime)
            ->assertJsonPath('data.estado', 'cancelada_por_cliente')
            ->assertJsonPath('data.clasificacion', 'a_tiempo');

        $this->assertSame('cancelled_by_customer', $this->statusOf($this->onTime));
        $this->assertSame([['confirmed', 'cancelled_by_customer', $this->ana]], $this->entriesOf($this->onTime));
    }

    /** A customer who cancels inside the deadline copied into the reservation is late (CA-01). */
    public function test_a_customer_cancels_late(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->cancelUrl($this->late))
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada_por_cliente')
            ->assertJsonPath('data.clasificacion', 'tardia');
    }

    /** A temporary reservation can be cancelled by its customer (RF-16, RE-04). */
    public function test_a_customer_cancels_a_temporary_reservation(): void
    {
        $this->actAs($this->ana);

        $this->postJson($this->cancelUrl($this->temporary))->assertOk()->assertJsonPath('data.estado', 'cancelada_por_cliente');
    }

    /** The owner cancels any reservation of the barbershop: it is "cancelada por la barbería", whoever its customer is (CA-04, RE-09). */
    public function test_the_owner_cancels_any_reservation_of_the_barbershop(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->cancelUrl($this->late))
            ->assertOk()
            ->assertJsonPath('data.estado', 'cancelada_por_barberia')
            ->assertJsonPath('data.clasificacion', 'por_la_barberia');
        $this->postJson($this->cancelUrl($this->luisReservation))->assertOk()->assertJsonPath('data.estado', 'cancelada_por_barberia');

        $this->assertSame([['confirmed', 'cancelled_by_barbershop', $this->owner]], $this->entriesOf($this->late));
    }

    /** A temporary reservation is not on the diagram of the barbershop's cancellation, so the owner gets a conflict (RE-04). */
    public function test_the_owner_cannot_cancel_a_temporary_reservation(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->cancelUrl($this->temporary))->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');
        $this->assertSame('temporary', $this->statusOf($this->temporary));
    }

    /** A collaborator cancels nothing, not even a reservation of their own agenda: 403 and the reservation stays (RF-16). */
    public function test_a_collaborator_gets_403(): void
    {
        $this->actAs($this->danielaUser);

        $this->postJson($this->cancelUrl($this->onTime))->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');
        $this->assertSame('confirmed', $this->statusOf($this->onTime));
    }

    /** A customer cannot cancel the reservation of another customer, a missing one or one of another barbershop: the same 404 (UP-06). */
    public function test_a_customer_does_not_reach_foreign_reservations(): void
    {
        $this->actAs($this->luis);

        $missing = $this->postJson($this->cancelUrl((string) Str::uuid7()))->assertNotFound();

        foreach ([$this->onTime, $this->fadeLabReservation] as $foreign) {
            $response = $this->postJson($this->cancelUrl($foreign))->assertNotFound();
            $this->assertSame($this->withoutInstance($missing->json()), $this->withoutInstance($response->json()));
        }

        $this->assertSame('confirmed', $this->statusOf($this->onTime));
        $this->assertSame('confirmed', $this->statusOf($this->fadeLabReservation, $this->fadeLab));
    }

    /** The owner of one barbershop cannot cancel a reservation of another, and a person without a role finds nothing (UP-06, MT-03). */
    public function test_a_reservation_of_another_barbershop_and_a_stranger_get_404(): void
    {
        $this->actAs($this->owner);
        $this->postJson($this->cancelUrl($this->fadeLabReservation))->assertNotFound();

        $stranger = $this->insertUser('Persona Nueva');
        $this->actAs($stranger);
        $this->postJson($this->cancelUrl($this->onTime))->assertNotFound();
        $this->assertSame(0, DB::table('memberships')->where('user_id', $stranger)->count());
    }

    /** Cancelling again answers 200 with the same status and class and adds nothing to the history (CA-06). */
    public function test_cancelling_again_changes_nothing(): void
    {
        $this->actAs($this->ana);
        $first = $this->postJson($this->cancelUrl($this->late))->assertOk();
        $entries = $this->entriesOf($this->late);

        $second = $this->postJson($this->cancelUrl($this->late))->assertOk();

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame('tardia', $second->json('data.clasificacion'));
        $this->assertSame($entries, $this->entriesOf($this->late));
        $this->assertCount(1, $entries);

        // The owner repeating it does not turn it into a cancellation of the barbershop.
        $this->actAs($this->owner);
        $this->postJson($this->cancelUrl($this->late))->assertOk()->assertJsonPath('data.estado', 'cancelada_por_cliente');
        $this->assertSame($entries, $this->entriesOf($this->late));
    }

    /** The repeat keeps the class of the first cancellation, even though the reservation is nearer then (CA-06). */
    public function test_a_repeat_keeps_the_class_of_the_first_cancellation(): void
    {
        $this->actAs($this->ana);
        $this->postJson($this->cancelUrl($this->onTime))->assertOk()->assertJsonPath('data.clasificacion', 'a_tiempo');

        DB::table('reservation_status_history')->where('reservation_id', $this->onTime)->where('to_status', 'cancelled_by_customer')->update(['changed_at' => '2030-03-05 09:00:00-06']);

        $this->postJson($this->cancelUrl($this->onTime))->assertOk()->assertJsonPath('data.clasificacion', 'tardia');
    }

    /** Cancelling frees the space in the same operation: someone else can book it right after, and not before (CA-07, RF-16). */
    public function test_the_space_can_be_booked_again_after_cancelling(): void
    {
        $this->actAs($this->luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/espacio-no-disponible');

        $this->actAs($this->ana);
        $this->postJson($this->cancelUrl($this->onTime))->assertOk();

        $this->actAs($this->luis);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated()->assertJsonPath('data.estado', 'confirmada');
    }

    /** A reservation that was attended or missed is final and cannot be cancelled, by the customer or by the owner (RE-04). */
    public function test_a_completed_or_missed_reservation_cannot_be_cancelled(): void
    {
        foreach ([$this->ana, $this->owner] as $person) {
            $this->actAs($person);

            foreach ([[$this->completed, 'completed'], [$this->noShow, 'no_show']] as [$id, $status]) {
                $this->postJson($this->cancelUrl($id))->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');
                $this->assertSame($status, $this->statusOf($id));
                $this->assertSame([], $this->entriesOf($id));
            }
        }
    }

    /** An expired reservation is final too (RE-04). */
    public function test_an_expired_reservation_cannot_be_cancelled(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('reservations')->where('id', $this->temporary)->update(['status' => 'expired']);

        $this->actAs($this->ana);
        $this->postJson($this->cancelUrl($this->temporary))->assertConflict();
        $this->assertSame('expired', $this->statusOf($this->temporary));
    }

    /** Without a session the cancellation answers 401. */
    public function test_without_a_session_the_cancellation_answers_401(): void
    {
        $this->postJson($this->cancelUrl($this->onTime))->assertUnauthorized();
        $this->assertSame('confirmed', $this->statusOf($this->onTime));
    }

    /** An identifier that is not a UUID is as missing as an unknown one. */
    public function test_an_identifier_that_is_not_a_uuid_is_a_404(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->cancelUrl('no-es-un-uuid'))->assertNotFound();
    }

    private function cancelUrl(string $reservationId): string
    {
        return $this->reservationsUrl($this->puraNavaja)."/{$reservationId}/cancelacion";
    }

    /** The stored status of a reservation. */
    private function statusOf(string $reservationId, ?string $barbershopId = null): string
    {
        $this->scopeQueriesTo($barbershopId ?? $this->puraNavaja);

        return (string) DB::table('reservations')->where('id', $reservationId)->value('status');
    }

    /**
     * The history entries of a reservation; the seed writes none, so these are the changes the cancellation made.
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
