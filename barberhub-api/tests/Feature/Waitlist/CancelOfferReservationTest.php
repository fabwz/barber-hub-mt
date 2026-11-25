<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use Illuminate\Support\Facades\DB;
use Src\Reservations\Reservations\Application\UseCases\ConfirmReservation;
use Src\Reservations\Reservations\Infrastructure\Jobs\ExpireTemporaryReservationJob;
use Src\Shared\Infrastructure\Tenancy\BarbershopProcessScope;
use Src\Waitlist\Offers\Application\UseCases\OfferReleasedSpace;
use Src\Waitlist\Offers\Infrastructure\Jobs\ExpireOfferJob;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * What happens to the offer and to its row when the temporary reservation made for the offer is cancelled, against PostgreSQL (LE-05, RF-32, PA-06):
 * cancelling it is the same as turning the offer down, so the offer is closed, the row goes back to waiting and the space goes to the next person,
 * never again to the one who cancelled. Today is Friday 1 March 2030, 8:00: a customer cancelled the 10:00 reservation of Tuesday with Daniela, the offer
 * for that space went to Ana, the first of three, until 8:15, and Ana accepted it in a barbershop that asks for a deposit, so her reservation waits for its payment.
 */
final class CancelOfferReservationTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $offer;

    private string $reservation;

    /** The space is freed by a cancellation and offered to Ana, who accepts it: the offer stays pending, pointing at a reservation that waits for its payment. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedOffers();
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();
        $this->offer = $this->offerRows()[0]->id;
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['payment_gateway' => 'onvo', 'payment_mode' => 'deposit', 'deposit_percentage' => 30]);
        $this->actAs($this->ana);
        $this->reservation = (string) $this->postJson($this->answerUrl('aceptacion'))->assertOk()->assertJsonPath('data.estado', 'pendiente')->json('data.reserva');
    }

    // ---- Cancelling the reservation of the offer -------------------------------------------------------------------------------------------------

    /** The offer is closed as turned down, the row goes back to waiting with its place and the reservation is cancelled (LE-05). */
    public function test_cancelling_the_reservation_closes_the_offer_and_returns_the_row_to_the_line(): void
    {
        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();

        $offer = $this->offerRows()[0];
        $this->assertSame('rejected', $offer->status);
        $this->assertNotNull($offer->responded_at);
        $this->assertSame($this->reservation, $offer->reservation_id);
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
        $this->assertSame('cancelled_by_customer', $this->reservationStatus($this->reservation));
        $this->getJson($this->offersUrl())->assertOk()->assertJsonCount(0, 'data');
    }

    /** The space goes to the next person in the same operation, as when an offer is turned down, and the offer of the next person holds it for its 15 minutes (LE-05). */
    public function test_the_space_is_offered_to_the_next_person(): void
    {
        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();

        $offers = $this->offerRows();
        $this->assertCount(2, $offers);
        $this->assertSame($this->luisEntry, $offers[1]->waitlist_entry_id);
        $this->assertSame('pending', $offers[1]->status);
        $this->assertNull($offers[1]->reservation_id);
        $this->assertSame('2030-03-05 10:00:00-06', $offers[1]->starts_at);
        $this->assertSame('offered', $this->entryStatus($this->luisEntry));
        $this->assertSame('waiting', $this->entryStatus($this->beaEntry));
    }

    /**
     * The person who cancelled is not offered the same space again, however the line moves and however many times the release runs: the exclusion of the
     * offers that were turned down applies to this closing as well (LE-05).
     */
    public function test_the_person_who_cancelled_is_never_offered_the_same_space_again(): void
    {
        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();

        foreach ([$this->luis, $this->bea] as $person) {
            $this->actAs($person);
            $pending = array_values(array_filter($this->offerRows(), fn (object $offer): bool => $offer->status === 'pending'));
            $this->assertCount(1, $pending);
            $this->postJson($this->answerUrl('rechazo', $pending[0]->id))->assertOk();
        }

        $offers = $this->offerRows();
        $this->assertSame([$this->anaEntry, $this->luisEntry, $this->beaEntry], array_map(fn (object $offer): string => $offer->waitlist_entry_id, $offers));
        $this->assertSame(['rejected', 'rejected', 'rejected'], array_map(fn (object $offer): string => $offer->status, $offers));
        $this->assertSame(['waiting', 'waiting', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);

        $this->releaseTheSpaceAgain();

        $this->assertCount(3, $this->offerRows());
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }

    // ---- What changes nothing --------------------------------------------------------------------------------------------------------------------

    /** Cancelling twice closes the offer once and offers the space once: the repeat finds the reservation cancelled and publishes nothing (CA-06). */
    public function test_cancelling_twice_closes_the_offer_once(): void
    {
        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();
        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();

        $this->assertSame(['rejected', 'pending'], array_map(fn (object $offer): string => $offer->status, $this->offerRows()));
        $this->assertCount(2, $this->offerRows());
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }

    /** Turning down the offer that cancelling closed answers as the offer is, without opening it again or offering the space twice (RF-32). */
    public function test_turning_down_a_closed_offer_is_idempotent(): void
    {
        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();

        $this->postJson($this->answerUrl('rechazo'))->assertOk()->assertJsonPath('data.estado', 'rechazada');

        $this->assertCount(2, $this->offerRows());
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }

    /** A reservation that no offer points at is cancelled as always: the offers and the rows of the line stay as they were. */
    public function test_cancelling_a_reservation_without_an_offer_changes_no_offer(): void
    {
        $before = $this->offerRows();
        $other = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->anaMembership, '2030-03-06 10:00:00-06');

        $this->postJson($this->cancelUrlOf($other))->assertOk();

        $this->assertEquals($before, $this->offerRows());
        $this->assertSame('pending', $before[0]->status);
        $this->assertSame('offered', $this->entryStatus($this->anaEntry));
    }

    /** A reservation of an offer that was paid is a normal booking: cancelling it leaves the accepted offer as the record of what happened, and its row fulfilled (PA-06). */
    public function test_cancelling_the_paid_reservation_of_an_accepted_offer_leaves_the_offer_accepted(): void
    {
        $this->confirm($this->reservation);
        $this->assertSame('fulfilled', $this->entryStatus($this->anaEntry));

        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();

        $this->assertSame('accepted', $this->offerRows()[0]->status);
        $this->assertSame('fulfilled', $this->entryStatus($this->anaEntry));
    }

    // ---- The expiry and the cancellation ---------------------------------------------------------------------------------------------------------

    /** Without a payment the offer expires as it always did: the reservation expires, the row goes back to waiting and the space is offered to the next person once (LE-04, LE-05). */
    public function test_an_unpaid_reservation_still_loses_the_offer_by_expiry(): void
    {
        $this->setNow('2030-03-01 08:15:01');
        $this->runReservationExpiry();
        $this->runOfferExpiry();

        $offers = $this->offerRows();
        $this->assertSame(['expired', 'pending'], array_map(fn (object $offer): string => $offer->status, $offers));
        $this->assertSame([$this->anaEntry, $this->luisEntry], array_map(fn (object $offer): string => $offer->waitlist_entry_id, $offers));
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
        $this->assertSame('expired', $this->reservationStatus($this->reservation));
    }

    /** If the expiry of the offer already ran, cancelling the reservation afterwards does not touch the closed offer (LE-05). */
    public function test_cancelling_after_the_offer_expired_changes_nothing(): void
    {
        $this->setNow('2030-03-01 08:15:01');
        $this->runOfferExpiry();
        $before = $this->offerRows();

        $this->postJson($this->cancelUrlOf($this->reservation));

        $this->assertEquals($before, $this->offerRows());
        $this->assertSame('expired', $before[0]->status);
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }

    /** If the cancellation came first, the expiry that follows finds the offer already closed and neither marks it nor offers the space again (LE-05). */
    public function test_the_expiry_after_a_cancellation_changes_nothing(): void
    {
        $this->postJson($this->cancelUrlOf($this->reservation))->assertOk();
        $before = $this->offerRows();

        $this->setNow('2030-03-01 08:15:01');
        $this->runOfferExpiry();
        $this->runOfferExpiry();

        $after = $this->offerRows();
        $this->assertEquals($before, array_slice($after, 0, 2));
        $this->assertSame('rejected', $after[0]->status);
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }

    /** A cancellation at the instant the 15 minutes pass leaves the offer to its own expiry, which closes it as expired and moves the line on once (LE-04, LE-05). */
    public function test_a_cancellation_after_the_deadline_is_left_to_the_expiry(): void
    {
        $this->setNow('2030-03-01 08:15:00');
        $this->postJson($this->cancelUrlOf($this->reservation));

        $this->assertSame('pending', $this->offerRows()[0]->status);

        $this->runOfferExpiry();

        $offers = $this->offerRows();
        $this->assertSame('expired', $offers[0]->status);
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
        $this->assertSame([$this->anaEntry, $this->luisEntry], array_map(fn (object $offer): string => $offer->waitlist_entry_id, $offers));
    }

    // ---- Helpers ---------------------------------------------------------------------------------------------------------------------------------

    private function answerUrl(string $answer, ?string $offer = null): string
    {
        return '/api/v1/barberias/'.$this->puraNavaja.'/ofertas/'.($offer ?? $this->offer).'/'.$answer;
    }

    private function offersUrl(): string
    {
        return "/api/v1/barberias/{$this->puraNavaja}/mis-ofertas";
    }

    private function reservationStatus(string $reservationId): string
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return (string) DB::table('reservations')->where('id', $reservationId)->value('status');
    }

    /** What the payments module does when the gateway confirms the payment: confirm the reservation as the barbershop. */
    private function confirm(string $reservationId): void
    {
        $this->app->make(BarbershopProcessScope::class)->run(
            $this->puraNavaja,
            fn () => $this->app->make(ConfirmReservation::class)->execute($reservationId, $this->clock->now()),
        );
    }

    /** The task of the queue that expires an offer. */
    private function runOfferExpiry(): void
    {
        $this->app->call((new ExpireOfferJob($this->offer, $this->puraNavaja))->handle(...));
    }

    /** The task of the queue that expires a temporary reservation. */
    private function runReservationExpiry(): void
    {
        $this->app->call((new ExpireTemporaryReservationJob($this->reservation, $this->puraNavaja))->handle(...));
    }

    /** What the queue does when the release of the space runs again, once or many times: offer the space of the first offer to the line. */
    private function releaseTheSpaceAgain(): void
    {
        $first = $this->offerRows()[0];

        $this->app->make(BarbershopProcessScope::class)->run(
            $this->puraNavaja,
            fn () => $this->app->make(OfferReleasedSpace::class)->execute($first->collaborator_id, $this->instant('2030-03-05 10:00:00'), $this->instant('2030-03-05 10:50:00'), $this->clock->now()),
        );
    }
}
