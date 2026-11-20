<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Src\Reservations\Reservations\Application\UseCases\ConfirmReservation;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Src\Waitlist\Offers\Infrastructure\Jobs\ExpireOfferJob;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Vouchers\SeedsVouchers;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * The customer answers an offer of the waitlist, from the request to the reservation, against PostgreSQL (RF-32, LE-04 to LE-06, RE-07, PA-06,
 * UP-09, CL-04): accepting with a voucher, with a payment or in a barbershop that charges nothing, turning it down, the offer past its 15 minutes,
 * the same answer twice, and who may answer. Today is Friday 1 March 2030, 8:00: a customer cancelled the 10:00 reservation of Tuesday with Daniela,
 * and the offer for that space went to Ana, the first of three, until 8:15.
 */
final class AnswerOfferTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsVouchers;
    use SeedsWaitlist;

    private string $offer;

    /** The space is freed by a cancellation, so the offer reaches Ana through the queue. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedOffers();
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();
        $this->offer = $this->offerRows()[0]->id;
        $this->actAs($this->ana);
    }

    // ---- Accepting with a voucher --------------------------------------------------------------------------------------------------------------

    /** With a voucher the reservation is confirmed at once without a deposit, a session is set aside, the offer is accepted and the row leaves the line (RF-32, RE-07, BO-04). */
    public function test_accepting_with_a_voucher_confirms_the_reservation_and_takes_the_row_out_of_the_line(): void
    {
        $this->charge();
        $voucher = $this->voucher();

        $response = $this->accept(withVoucher: true)->assertOk()
            ->assertJsonPath('data.id', $this->offer)
            ->assertJsonPath('data.estado', 'aceptada')
            ->assertJsonPath('data.barbero', $this->daniela)
            ->assertJsonPath('data.inicio', '2030-03-05T10:00:00-06:00');

        $reservation = $response->json('data.reserva');
        $this->assertNotNull($reservation);
        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('reservations')->where('id', $reservation)->first();
        $this->assertNotNull($row);
        $this->assertSame('confirmed', $row->status);
        $this->assertNull($row->expires_at);
        $this->assertSame($this->anaMembership, $row->customer_membership_id);
        $this->assertSame($voucher, $row->purchased_voucher_id);
        $this->assertSame('2030-03-05 10:00:00-06', $row->starts_at);
        $this->assertSame(4, (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions'));
        $this->assertSame(0, DB::table('payments')->count());
        $offer = $this->offerRows()[0];
        $this->assertSame('accepted', $offer->status);
        $this->assertSame($reservation, $offer->reservation_id);
        $this->assertNotNull($offer->responded_at);
        $this->assertSame('fulfilled', $this->entryStatus($this->anaEntry));
        $this->assertSame(['waiting', 'waiting'], [$this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
        $this->assertCount(1, $this->offerRows());
    }

    /** A voucher without sessions refuses the acceptance with `saldo-insuficiente` and nothing changes: the offer is still pending (BO-03). */
    public function test_a_person_without_vouchers_cannot_accept_with_one_and_the_offer_stays_open(): void
    {
        $this->charge();

        $this->accept(withVoucher: true)->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/saldo-insuficiente');

        $this->assertNothingWasBooked();
    }

    /** A voucher that ends before the day of the space refuses it with `bono-vencido` and nothing changes (BO-06). */
    public function test_an_expired_voucher_refuses_the_acceptance_and_the_offer_stays_open(): void
    {
        $this->charge();
        $voucher = $this->voucher(validityDays: 2);

        $this->accept(withVoucher: true)->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/bono-vencido');

        $this->assertNothingWasBooked();
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(5, (int) DB::table('purchased_vouchers')->where('id', $voucher)->value('remaining_sessions'));
    }

    /** A refused acceptance can be tried again with another way to pay inside the same 15 minutes (LE-04). */
    public function test_a_refused_acceptance_can_be_answered_again(): void
    {
        $this->charge();
        $this->accept(withVoucher: true)->assertConflict();

        $this->accept()->assertOk()->assertJsonPath('data.estado', 'pendiente');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('temporary', DB::table('reservations')->where('customer_membership_id', $this->anaMembership)->value('status'));
    }

    // ---- Accepting without a voucher -----------------------------------------------------------------------------------------------------------

    /** In a barbershop that charges nothing the reservation is confirmed at once, so the offer is accepted and the row fulfilled (RE-07). */
    public function test_accepting_in_a_barbershop_that_charges_nothing_confirms_at_once(): void
    {
        $response = $this->accept()->assertOk()->assertJsonPath('data.estado', 'aceptada');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('confirmed', DB::table('reservations')->where('id', $response->json('data.reserva'))->value('status'));
        $this->assertSame('accepted', $this->offerRows()[0]->status);
        $this->assertSame('fulfilled', $this->entryStatus($this->anaEntry));
    }

    /**
     * With a payment the temporary reservation is made over the offered space and has until the end of the offer to be paid, not the 10 minutes of
     * a common one (LE-04, RE-05). The offer stays pending, the row stays offered and the space is not offered to anybody else (PA-06).
     */
    public function test_accepting_with_a_payment_makes_a_temporary_reservation_and_the_offer_stays_open(): void
    {
        $this->charge();

        $response = $this->accept()->assertOk()
            ->assertJsonPath('data.estado', 'pendiente')
            ->assertJsonPath('data.vence_en', '2030-03-01T08:15:00-06:00');

        $reservation = $response->json('data.reserva');
        $this->scopeQueriesTo($this->puraNavaja);
        $row = DB::table('reservations')->where('id', $reservation)->first();
        $this->assertNotNull($row);
        $this->assertSame('temporary', $row->status);
        $this->assertSame('2030-03-01 08:15:00-06', $row->expires_at);
        $this->assertSame('2030-03-05 10:00:00-06', $row->starts_at);
        $offer = $this->offerRows()[0];
        $this->assertSame('pending', $offer->status);
        $this->assertSame($reservation, $offer->reservation_id);
        $this->assertNull($offer->responded_at);
        $this->assertSame('offered', $this->entryStatus($this->anaEntry));
        $this->assertCount(1, $this->offerRows());
        $this->getJson($this->offersUrl())->assertOk()->assertJsonPath('data.0.reserva', $reservation);
    }

    /** Accepting again while that reservation waits for its payment answers the same offer and the same reservation, without booking another (RF-32). */
    public function test_accepting_twice_with_a_payment_books_once(): void
    {
        $this->charge();
        $first = $this->accept()->assertOk()->json('data.reserva');

        $this->accept()->assertOk()->assertJsonPath('data.reserva', $first)->assertJsonPath('data.estado', 'pendiente');

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(1, DB::table('reservations')->where('customer_membership_id', $this->anaMembership)->count());
    }

    /** The payment confirmed in time accepts the offer and takes the row out of the line: nothing the browser says does it (PA-06, RF-32). */
    public function test_the_confirmed_payment_accepts_the_offer_and_fulfils_the_row(): void
    {
        $this->charge();
        $reservation = $this->accept()->assertOk()->json('data.reserva');
        $this->assertSame('pending', $this->offerRows()[0]->status);

        $this->setNow('2030-03-01 08:14:59');
        $this->confirm($reservation);

        $offer = $this->offerRows()[0];
        $this->assertSame('accepted', $offer->status);
        $this->assertNotNull($offer->responded_at);
        $this->assertSame('fulfilled', $this->entryStatus($this->anaEntry));
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('confirmed', DB::table('reservations')->where('id', $reservation)->value('status'));
        $this->getJson($this->offersUrl())->assertOk()->assertExactJson(['data' => []]);

        $this->setNow('2030-03-01 08:15:01');
        $this->runExpiry($this->offer);
        $this->assertSame('accepted', $this->offerRows()[0]->status);
        $this->assertCount(1, $this->offerRows());
    }

    /**
     * When the payment does not arrive before the 15 minutes the offer is lost: it expires, the row goes back to waiting with its place and the same
     * space goes to the next person, who is offered it (LE-04, LE-05). The late payment cannot confirm the reservation any more (RE-05).
     */
    public function test_an_unpaid_offer_is_lost_and_the_space_moves_on(): void
    {
        $this->charge();
        $reservation = $this->accept()->assertOk()->json('data.reserva');

        $this->setNow('2030-03-01 08:15:01');
        $this->runExpiry($this->offer);

        $offers = $this->offerRows();
        $this->assertSame('expired', $offers[0]->status);
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
        $this->assertCount(2, $offers);
        $this->assertSame($this->luisEntry, $offers[1]->waitlist_entry_id);
        $this->assertSame('pending', $offers[1]->status);
        $this->assertSame('offered', $this->entryStatus($this->luisEntry));
        $this->assertSame(false, $this->tryToConfirm($reservation));
        $this->accept()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/oferta-vencida');
    }

    // ---- Turning it down -----------------------------------------------------------------------------------------------------------------------

    /** Turning it down passes the offer to the next person in the same operation, without waiting for the 15 minutes (RF-32, LE-05). */
    public function test_rejecting_offers_the_space_to_the_next_person_at_once(): void
    {
        $this->reject()->assertOk()->assertJsonPath('data.estado', 'rechazada')->assertJsonPath('data.reserva', null);

        $offers = $this->offerRows();
        $this->assertSame('rejected', $offers[0]->status);
        $this->assertNotNull($offers[0]->responded_at);
        $this->assertCount(2, $offers);
        $this->assertSame($this->luisEntry, $offers[1]->waitlist_entry_id);
        $this->assertSame('pending', $offers[1]->status);
        $this->assertSame('2030-03-05 10:00:00-06', $offers[1]->starts_at);
        $this->assertSame('2030-03-01 08:15:00-06', $offers[1]->expires_at);
        $this->assertSame('offered', $this->entryStatus($this->luisEntry));
    }

    /** The person who turned it down keeps their place in the line, but is not offered that same space again (LE-05). */
    public function test_the_person_who_rejected_keeps_their_place_and_is_not_offered_the_same_space(): void
    {
        $this->reject()->assertOk();

        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
        $this->assertSame([$this->luisEntry], array_map(fn (object $offer): string => $offer->waitlist_entry_id, array_slice($this->offerRows(), 1)));

        $this->actAs($this->luis);
        $this->postJson($this->answerUrl($this->offerRows()[1]->id, 'rechazo'))->assertOk();
        $this->assertSame(['waiting', 'offered'], [$this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
        $this->assertSame([$this->anaEntry, $this->luisEntry, $this->beaEntry], array_map(fn (object $offer): string => $offer->waitlist_entry_id, $this->offerRows()));
    }

    /** When the last person turns it down the space is public again: nobody holds it (LE-05). */
    public function test_when_nobody_is_left_the_space_is_public_again(): void
    {
        foreach ([$this->ana, $this->luis, $this->bea] as $position => $person) {
            $this->actAs($person);
            $mine = array_values(array_filter($this->offerRows(), fn (object $offer): bool => $offer->status === 'pending'));
            $this->assertCount(1, $mine, "Offer {$position} has a pending offer to turn down.");
            $this->postJson($this->answerUrl($mine[0]->id, 'rechazo'))->assertOk();
        }

        $this->assertCount(3, $this->offerRows());
        $this->assertSame(['rejected', 'rejected', 'rejected'], array_map(fn (object $offer): string => $offer->status, $this->offerRows()));
        $this->assertSame(['waiting', 'waiting', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
        $this->actAs($this->stranger());
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
    }

    /** A reservation that waits for its payment must be cancelled first: turning the offer down is refused, and the reservation is not left alone to be paid (RF-32). */
    public function test_rejecting_is_refused_while_the_reservation_waits_for_its_payment(): void
    {
        $this->charge();
        $this->accept()->assertOk();

        $this->reject()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');

        $this->assertSame('pending', $this->offerRows()[0]->status);
        $this->assertCount(1, $this->offerRows());
    }

    // ---- The offer past its time ---------------------------------------------------------------------------------------------------------------

    /** At the instant the 15 minutes pass both answers are `oferta-vencida`, even though the task of the queue did not mark the offer yet (LE-04, LE-05). */
    public function test_answering_at_the_deadline_is_refused_as_expired_before_the_task_runs(): void
    {
        $this->setNow('2030-03-01 08:15:00');

        $this->accept()->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'http://localhost/problemas/oferta-vencida')
            ->assertJsonPath('status', 409);
        $this->reject()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/oferta-vencida');

        $this->assertSame('pending', $this->offerRows()[0]->status);
        $this->assertSame('offered', $this->entryStatus($this->anaEntry));
        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
        $this->assertCount(1, $this->offerRows());
    }

    /** After the task marked it expired the answer is the same, for both ways (LE-05). */
    public function test_answering_an_offer_that_already_expired_is_refused_as_expired(): void
    {
        $this->setNow('2030-03-01 08:15:01');
        $this->runExpiry($this->offer);

        $this->accept()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/oferta-vencida');
        $this->reject()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/oferta-vencida');
        $this->assertSame('expired', $this->offerRows()[0]->status);
        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
    }

    /** The last second still counts: at 8:14:59 the offer can be accepted (LE-04). */
    public function test_the_last_second_still_counts(): void
    {
        $this->setNow('2030-03-01 08:14:59');

        $this->accept()->assertOk()->assertJsonPath('data.estado', 'aceptada');
    }

    // ---- The same answer twice -----------------------------------------------------------------------------------------------------------------

    /** Accepting an accepted offer answers 200 with the same offer and reservation, and books nothing else (RF-32). */
    public function test_accepting_twice_is_idempotent(): void
    {
        $first = $this->accept()->assertOk()->json('data.reserva');

        $this->accept()->assertOk()->assertJsonPath('data.estado', 'aceptada')->assertJsonPath('data.reserva', $first);

        $this->assertSame(1, $this->reservationsOf($this->anaMembership));
    }

    /** Turning an offer down twice answers 200 and does not offer the space again (RF-32). */
    public function test_rejecting_twice_is_idempotent(): void
    {
        $this->reject()->assertOk();

        $this->reject()->assertOk()->assertJsonPath('data.estado', 'rechazada');

        $this->assertCount(2, $this->offerRows());
    }

    /** The opposite answer to the one the offer has is a conflict, either way round (RF-32). */
    public function test_the_opposite_answer_is_a_conflict(): void
    {
        $this->accept()->assertOk();
        $this->reject()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');
        $this->assertSame('accepted', $this->offerRows()[0]->status);
    }

    /** A rejected offer is not accepted afterwards. */
    public function test_a_rejected_offer_is_not_accepted_afterwards(): void
    {
        $this->reject()->assertOk();

        $this->accept()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');

        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
    }

    /** The person who left the line while the offer was pending has nothing to accept (RF-30). */
    public function test_an_offer_whose_row_was_left_cannot_be_accepted(): void
    {
        $this->deleteJson("/api/v1/barberias/{$this->puraNavaja}/filas/{$this->anaEntry}")->assertNoContent();

        $this->accept()->assertConflict()->assertJsonPath('type', 'http://localhost/problemas/conflicto');

        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
    }

    // ---- The offer of the customer -------------------------------------------------------------------------------------------------------------

    /** The customer opens their open offer with the space, who gives it and until when (RF-32). */
    public function test_the_customer_lists_their_open_offer_with_its_space(): void
    {
        $this->getJson($this->offersUrl())->assertOk()->assertExactJson(['data' => [[
            'id' => $this->offer,
            'fila' => $this->anaEntry,
            'servicio' => $this->degradado,
            'barbero' => $this->daniela,
            'inicio' => '2030-03-05T10:00:00-06:00',
            'fin' => '2030-03-05T10:50:00-06:00',
            'vence_en' => '2030-03-01T08:15:00-06:00',
            'estado' => 'pendiente',
            'reserva' => null,
        ]]]);
    }

    /** Nobody sees the offers of others, a person without a role sees none, and the list creates no membership (CL-04, RD-18). */
    public function test_each_customer_lists_only_their_own_offers(): void
    {
        $this->actAs($this->luis);
        $this->getJson($this->offersUrl())->assertOk()->assertExactJson(['data' => []]);

        $stranger = $this->stranger();
        $this->actAs($stranger);
        $this->getJson($this->offersUrl())->assertOk()->assertExactJson(['data' => []]);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('memberships')->where('user_id', $stranger)->count());
    }

    /** An offer past its time, accepted or turned down no longer shows (LE-05). */
    public function test_an_offer_that_cannot_be_answered_does_not_show(): void
    {
        $this->setNow('2030-03-01 08:15:00');
        $this->getJson($this->offersUrl())->assertOk()->assertExactJson(['data' => []]);

        $this->setNow('2030-03-01 08:05:00');
        $this->reject()->assertOk();
        $this->getJson($this->offersUrl())->assertOk()->assertExactJson(['data' => []]);
    }

    // ---- LE-06 ---------------------------------------------------------------------------------------------------------------------------------

    /** Booking the same service and day another way takes the person out of the line, whichever the hour or the barber (LE-06). */
    public function test_booking_the_same_service_and_day_another_way_leaves_the_line(): void
    {
        $other = $this->insertWaitlistEntry($this->puraNavaja, $this->luisMembership, $this->degradado, $this->kevin, joinedAt: '2030-03-01 09:00:00-06');
        $nextDay = $this->insertWaitlistEntry($this->puraNavaja, $this->luisMembership, $this->degradado, date: '2030-03-06');
        $this->actAs($this->luis);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated()->assertJsonPath('data.estado', 'confirmada');

        $this->assertSame(['left', 'left', 'waiting'], [$this->entryStatus($this->luisEntry), $this->entryStatus($other), $this->entryStatus($nextDay)]);
        $this->assertSame(['offered', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->beaEntry)]);
    }

    /** A reservation that is only temporary does not leave the line: it may expire, so the person leaves only when it is confirmed (LE-06, RE-05). */
    public function test_a_temporary_reservation_does_not_leave_the_line_until_it_is_confirmed(): void
    {
        $this->charge();
        $this->actAs($this->luis);

        $reservation = $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated()->assertJsonPath('data.estado', 'temporal')->json('data.id');
        $this->assertSame('waiting', $this->entryStatus($this->luisEntry));

        $this->confirm($reservation);
        $this->assertSame('left', $this->entryStatus($this->luisEntry));
    }

    /** A person who holds an offer and books the same service and day another way loses that offer and the line, and the space passes on (LE-06, LE-05). */
    public function test_booking_another_way_while_holding_an_offer_hands_the_space_on(): void
    {
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking(startsAt: '2030-03-05T14:00:00-06:00'))->assertCreated();

        $offers = $this->offerRows();
        $this->assertSame('left', $this->entryStatus($this->anaEntry));
        $this->assertSame('rejected', $offers[0]->status);
        $this->assertCount(2, $offers);
        $this->assertSame($this->luisEntry, $offers[1]->waitlist_entry_id);
        $this->assertSame('pending', $offers[1]->status);
        $this->assertSame('offered', $this->entryStatus($this->luisEntry));
    }

    /** The row fulfilled by the offer is not touched again by the reservation it answers, and the others of the person stay (LE-06). */
    public function test_the_reservation_of_the_offer_leaves_the_other_rows_of_the_day(): void
    {
        $other = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, $this->kevin, joinedAt: '2030-03-01 09:00:00-06');
        $nextDay = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, date: '2030-03-06');

        $this->accept()->assertOk();

        $this->assertSame(['fulfilled', 'left', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($other), $this->entryStatus($nextDay)]);
    }

    // ---- Who may answer ------------------------------------------------------------------------------------------------------------------------

    /** The owner and a collaborator of the barbershop are refused with 403 on the three routes, and nothing changes (RD-18, UP-09). */
    public function test_the_owner_and_a_collaborator_are_refused(): void
    {
        $collaborator = (string) DB::table('collaborators')->where('id', $this->kevin)->value('user_id');

        foreach ([$this->owner, $collaborator] as $person) {
            $this->actAs($person);

            $this->getJson($this->offersUrl())->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');
            $this->accept()->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');
            $this->reject()->assertForbidden()->assertJsonPath('type', 'http://localhost/problemas/prohibido');
        }

        $this->assertSame('pending', $this->offerRows()[0]->status);
        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
    }

    /** The offer of another customer, one that does not exist and a person without a role answer the same 404 (CL-04, MT-03). */
    public function test_the_offer_of_another_person_reads_like_one_that_does_not_exist(): void
    {
        $missing = '0192f3a0-0000-7000-8000-00000000dead';
        $this->actAs($this->luis);
        $ofAnother = $this->withoutInstance($this->accept()->assertNotFound()->json());
        $this->assertSame($ofAnother, $this->withoutInstance($this->postJson($this->answerUrl($missing, 'aceptacion'))->assertNotFound()->json()));
        $this->assertSame($ofAnother, $this->withoutInstance($this->reject()->assertNotFound()->json()));
        $this->actAs($this->stranger());
        $this->assertSame($ofAnother, $this->withoutInstance($this->accept()->assertNotFound()->json()));
        $this->assertSame($ofAnother, $this->withoutInstance($this->reject()->assertNotFound()->json()));

        $this->assertSame('pending', $this->offerRows()[0]->status);
        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
        $this->assertSame(0, $this->reservationsOf($this->luisMembership));
    }

    /** The offer of one barbershop is not reachable from another, even for a person who is its customer: the id of the offer means nothing there (MT-03, CL-04). */
    public function test_an_offer_is_not_reachable_from_another_barbershop(): void
    {
        $this->postJson("/api/v1/barberias/{$this->fadeLab}/ofertas/{$this->offer}/aceptacion")->assertNotFound()->assertJsonPath('type', 'http://localhost/problemas/no-encontrado');
        $this->postJson("/api/v1/barberias/{$this->fadeLab}/ofertas/{$this->offer}/rechazo")->assertNotFound();
        $this->getJson("/api/v1/barberias/{$this->fadeLab}/mis-ofertas")->assertOk()->assertExactJson(['data' => []]);

        $this->assertSame('pending', $this->offerRows()[0]->status);
        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
    }

    /** Without a session the routes answer 401. */
    public function test_a_request_without_a_session_is_refused(): void
    {
        $this->app->make('auth')->forgetGuards();

        $this->getJson($this->offersUrl())->assertUnauthorized();
        $this->postJson($this->answerUrl($this->offer, 'aceptacion'))->assertUnauthorized();
        $this->postJson($this->answerUrl($this->offer, 'rechazo'))->assertUnauthorized();
    }

    // ---- Helpers -------------------------------------------------------------------------------------------------------------------------------

    /** @return TestResponse<Response> */
    private function accept(bool $withVoucher = false): TestResponse
    {
        return $this->postJson($this->answerUrl($this->offer, 'aceptacion'), $withVoucher ? ['con_bono' => true] : []);
    }

    /** @return TestResponse<Response> */
    private function reject(): TestResponse
    {
        return $this->postJson($this->answerUrl($this->offer, 'rechazo'));
    }

    private function answerUrl(string $offerId, string $answer): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/ofertas/{$offerId}/{$answer}";
    }

    private function offersUrl(): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/mis-ofertas";
    }

    /** Pura Navaja asks for a 30 % deposit, so a reservation without a voucher is temporary until it is paid. */
    private function charge(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo', 'payment_mode' => 'deposit', 'deposit_percentage' => 30]);
    }

    /** A voucher Ana bought for "Degradado", paid on 1 March 2030. */
    private function voucher(int $sessions = 5, int $validityDays = 90): string
    {
        $definition = $this->insertVoucherDefinition($this->puraNavaja, [$this->degradado]);

        return $this->insertPurchasedVoucher($this->puraNavaja, $this->anaMembership, $definition, 'active', $sessions, $validityDays);
    }

    /** A customer of nowhere: they have no role in Pura Navaja. */
    private function stranger(): string
    {
        return $this->insertUser('Nora Vega');
    }

    /** What the payments module does when the gateway confirms the payment: confirm the reservation as the barbershop. */
    private function confirm(string $reservationId): void
    {
        $this->app->make(BarbershopProcessScope::class)->run($this->puraNavaja, fn () => $this->app->make(ConfirmReservation::class)->execute($reservationId, $this->clock->now()));
    }

    /** Whether the reservation could be confirmed now; a late payment cannot (RE-05). */
    private function tryToConfirm(string $reservationId): bool
    {
        try {
            $this->confirm($reservationId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The task of the queue that expires an offer. */
    private function runExpiry(string $offerId): void
    {
        $job = new ExpireOfferJob($offerId, $this->puraNavaja);
        $this->app->call($job->handle(...));
    }

    private function reservationsOf(string $membershipId): int
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return DB::table('reservations')->where('customer_membership_id', $membershipId)->whereIn('status', ['confirmed', 'temporary'])->count();
    }

    /** A refused acceptance leaves no reservation, no voucher session and no answer on the offer. */
    private function assertNothingWasBooked(): void
    {
        $offer = $this->offerRows()[0];
        $this->assertSame('pending', $offer->status);
        $this->assertNull($offer->reservation_id);
        $this->assertNull($offer->responded_at);
        $this->assertSame('offered', $this->entryStatus($this->anaEntry));
        $this->assertSame(0, $this->reservationsOf($this->anaMembership));
        $this->assertCount(1, $this->offerRows());
    }
}
