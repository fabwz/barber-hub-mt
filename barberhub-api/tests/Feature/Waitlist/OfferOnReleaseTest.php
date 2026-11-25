<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Reservations\Reservations\Infrastructure\Jobs\ExpireTemporaryReservationJob;
use Src\Waitlist\Offers\Domain\Events\OfferCreated;
use Src\Waitlist\Offers\Infrastructure\Jobs\ExpireOfferJob;
use Src\Waitlist\Offers\Infrastructure\Listeners\OfferSpaceReleased;
use stdClass;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * The three ways a space is freed, a cancellation, an expiry and a reschedule, offer it to the first compatible person of the line, from the
 * request to the offer, against PostgreSQL and through the queue (RF-31, CA-07, LE-02 to LE-05). The queue is the sync driver of the tests, so the
 * listener runs right after the transaction that freed the space commits, as a worker would. Today is Friday 1 March 2030, 8:00.
 */
final class OfferOnReleaseTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsWaitlist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedOffers();
    }

    /** When the customer cancels, the first of three people in the line receives the offer, and only they (RF-31, CA-07, LE-02). */
    public function test_a_cancellation_by_the_customer_offers_the_space_to_the_first_in_the_line(): void
    {
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk()->assertJsonPath('data.estado', 'cancelada_por_cliente');

        $offers = $this->offerRows();
        $this->assertCount(1, $offers);
        $this->assertSame($this->anaEntry, $offers[0]->waitlist_entry_id);
        $this->assertSame($this->daniela, $offers[0]->collaborator_id);
        $this->assertSame('pending', $offers[0]->status);
        $this->assertSame(['offered', 'waiting', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
    }

    /** The offer holds the whole space freed, cleaning included, and lasts 15 minutes from the moment it is made (LE-04, DI-05). */
    public function test_the_offer_holds_the_space_for_fifteen_minutes(): void
    {
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $offer = $this->offerRows()[0];
        $this->assertSame('2030-03-05 10:00:00-06', $offer->starts_at);
        $this->assertSame('2030-03-05 10:50:00-06', $offer->ends_at);
        $this->assertSame('2030-03-01 08:15:00-06', $offer->expires_at);
        $this->assertSame('2030-03-01 08:00:00-06', $offer->created_at);
    }

    /** The cancellation by the barbershop frees the space the same way (CA-07). */
    public function test_a_cancellation_by_the_barbershop_offers_the_space_too(): void
    {
        $this->actAs($this->owner);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk()->assertJsonPath('data.estado', 'cancelada_por_barberia');

        $this->assertSame([$this->anaEntry], array_map(fn (stdClass $offer): string => $offer->waitlist_entry_id, $this->offerRows()));
    }

    /** Repeating the cancellation does not offer the space twice (CA-06). */
    public function test_repeating_the_cancellation_offers_the_space_once(): void
    {
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertCount(1, $this->offerRows());
    }

    /** When a temporary reservation expires without payment, the task that closes its window offers the space (RE-05, RF-31). */
    public function test_an_expired_temporary_reservation_offers_its_space(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('reservations')->where('id', $this->bookedReservation)->update(['status' => 'temporary', 'expires_at' => '2030-03-01 08:10:00-06']);
        $this->setNow('2030-03-01 08:10:01');

        $job = new ExpireTemporaryReservationJob($this->bookedReservation, $this->puraNavaja);
        $this->app->call($job->handle(...));

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('expired', DB::table('reservations')->where('id', $this->bookedReservation)->value('status'));
        $this->assertSame([$this->anaEntry], array_map(fn (stdClass $offer): string => $offer->waitlist_entry_id, $this->offerRows()));
    }

    /** When a reservation moves, the time it held before is offered to the line, and the new time is not (RF-31, RE-08). */
    public function test_a_rescheduled_reservation_offers_the_space_it_left(): void
    {
        $this->actAs($this->booker);

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$this->bookedReservation}", ['inicio' => '2030-03-05T14:00:00-06:00'])->assertOk();

        $offers = $this->offerRows();
        $this->assertCount(1, $offers);
        $this->assertSame('2030-03-05 10:00:00-06', $offers[0]->starts_at);
        $this->assertSame('2030-03-05 10:50:00-06', $offers[0]->ends_at);
        $this->assertSame($this->anaEntry, $offers[0]->waitlist_entry_id);
    }

    /** The owner moves a reservation with no deadline (CA-07), and the time it held goes to the first of the line the same way (RF-31). */
    public function test_a_reschedule_by_the_owner_offers_the_space_it_left(): void
    {
        $this->actAs($this->owner);

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$this->bookedReservation}", ['inicio' => '2030-03-05T14:00:00-06:00'])->assertOk();

        $offers = $this->offerRows();
        $this->assertCount(1, $offers);
        $this->assertSame($this->anaEntry, $offers[0]->waitlist_entry_id);
        $this->assertSame($this->daniela, $offers[0]->collaborator_id);
        $this->assertSame('2030-03-05 10:00:00-06', $offers[0]->starts_at);
        $this->assertSame('2030-03-05 10:50:00-06', $offers[0]->ends_at);
        $this->assertSame('pending', $offers[0]->status);
        $this->assertSame(['offered', 'waiting', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
    }

    /** Moving to another collaborator at the same time frees the space of the collaborator it left, which is the one offered, not the one it went to (RF-31). */
    public function test_a_reschedule_to_another_collaborator_offers_the_old_space(): void
    {
        $this->actAs($this->booker);

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$this->bookedReservation}", ['inicio' => '2030-03-05T10:00:00-06:00', 'barbero' => $this->kevin])
            ->assertOk()
            ->assertJsonPath('data.barbero', $this->kevin);

        $offers = $this->offerRows();
        $this->assertCount(1, $offers);
        $this->assertSame($this->anaEntry, $offers[0]->waitlist_entry_id);
        $this->assertSame($this->daniela, $offers[0]->collaborator_id);
        $this->assertSame('2030-03-05 10:00:00-06', $offers[0]->starts_at);
        $this->assertSame('2030-03-05 10:50:00-06', $offers[0]->ends_at);
        $this->assertSame('pending', $offers[0]->status);
    }

    /** The space freed by a reservation that moves only a quarter of an hour is not whole, so nothing is offered. */
    public function test_a_reschedule_that_overlaps_the_old_space_offers_nothing(): void
    {
        $this->actAs($this->booker);

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$this->bookedReservation}", ['inicio' => '2030-03-05T10:15:00-06:00'])->assertOk();

        $this->assertSame([], $this->offerRows());
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }

    /** A hole too small for the first person goes to the next who fits: a customer who waits for 90 minutes is skipped (RF-31, LE-03). */
    public function test_a_hole_too_small_for_the_first_goes_to_the_next_compatible(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        $beard = $this->insertService($this->puraNavaja, 'Barba y corte', 90, 12000);
        DB::table('collaborator_services')->insert(['id' => (string) Str::uuid7(), 'barbershop_id' => $this->puraNavaja, 'collaborator_id' => $this->daniela, 'service_id' => $beard]);
        DB::table('waitlist_entries')->where('id', $this->anaEntry)->update(['service_id' => $beard]);
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertSame([$this->luisEntry], array_map(fn (stdClass $offer): string => $offer->waitlist_entry_id, $this->offerRows()));
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry), 'The person who waits for 90 minutes keeps their place.');
    }

    /** With less than 60 minutes before the space, nothing is offered; with exactly 60, it is (LE-03). */
    public function test_with_fewer_than_sixty_minutes_nothing_is_offered(): void
    {
        $this->actAs($this->booker);
        $this->setNow('2030-03-05 09:01');

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertSame([], $this->offerRows());
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }

    /** At exactly 60 minutes the space is still offered. */
    public function test_with_exactly_sixty_minutes_it_is_offered(): void
    {
        $this->actAs($this->booker);
        $this->setNow('2030-03-05 09:00');

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertCount(1, $this->offerRows());
    }

    /** With the waitlist switched off no new offer is made (RF-09). */
    public function test_with_the_waitlist_off_nothing_is_offered(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['waitlist_enabled' => false]);
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertSame([], $this->offerRows());
    }

    /** A person who has their own reservation that overlaps the space is skipped (LE-03). */
    public function test_a_person_with_their_own_reservation_in_the_space_is_skipped(): void
    {
        $this->insertReservation($this->puraNavaja, $this->kevin, $this->degradado, $this->anaMembership, '2030-03-05T10:15:00-06:00');
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertSame([$this->luisEntry], array_map(fn (stdClass $offer): string => $offer->waitlist_entry_id, $this->offerRows()));
    }

    /** A person who holds a pending offer for another row that overlaps the space is not offered a second, overlapping one (LE-06). */
    public function test_a_person_never_receives_two_overlapping_offers(): void
    {
        $anaOther = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, $this->kevin, '2030-03-05', 'offered', '2030-03-01 07:00:00-06');
        $this->insertOfferRow($anaOther, $this->kevin, '10:30', '11:20');
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $offers = array_values(array_filter($this->offerRows(), fn (stdClass $offer): bool => $offer->collaborator_id === $this->daniela));
        $this->assertSame([$this->luisEntry], array_map(fn (stdClass $offer): string => $offer->waitlist_entry_id, $offers));
    }

    /** The offer is announced once for the notices, and the cancellation answers without waiting for anything else (RF-31, RF-34, RF-35). */
    public function test_the_offer_is_announced_for_the_notices(): void
    {
        Event::fake([OfferCreated::class]);
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        Event::assertDispatchedTimes(OfferCreated::class, 1);
        Event::assertDispatched(OfferCreated::class, fn (OfferCreated $event): bool => $event->barbershopId === $this->puraNavaja
            && $event->waitlistEntryId === $this->anaEntry
            && $event->customerMembershipId === $this->anaMembership
            && $event->serviceId === $this->degradado
            && $event->collaboratorId === $this->daniela
            && $event->expiresAt->format('H:i') === '08:15');
    }

    /** Processing the same event twice, as the retry of a queue may, does not duplicate the offer (RNF-09). */
    public function test_processing_the_same_event_twice_offers_once(): void
    {
        $this->freeTheSpace();
        $listener = $this->app->make(OfferSpaceReleased::class);

        $listener->handle($this->releasedSpace());
        $listener->handle($this->releasedSpace());

        $this->assertCount(1, $this->offerRows());
        $this->assertSame('waiting', $this->entryStatus($this->luisEntry));
    }

    /** The listener leaves one task for the end of the offer, with its barbershop, for one second after its 15 minutes, so the line moves on in a minute at most (LE-04, LE-05). */
    public function test_the_task_of_the_offer_runs_within_a_minute_of_its_expiry(): void
    {
        Queue::fake();
        $this->freeTheSpace();
        $this->app->make(OfferSpaceReleased::class)->handle($this->releasedSpace());

        $offer = $this->offerRows()[0];
        Queue::assertPushed(ExpireOfferJob::class, 1);
        Queue::assertPushed(ExpireOfferJob::class, function (ExpireOfferJob $job) use ($offer): bool {
            $this->assertInstanceOf(DateTimeInterface::class, $job->delay);
            $lateness = $job->delay->getTimestamp() - $this->instant('2030-03-01 08:15:00')->getTimestamp();

            return $job->offerId === $offer->id && $job->barbershopId === $this->puraNavaja && $lateness >= 0 && $lateness <= 60;
        });
    }

    /** Cancel the reservation of the test straight in the table, so the space is free when the listener of the event runs without a request. */
    private function freeTheSpace(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('reservations')->where('id', $this->bookedReservation)->update(['status' => 'cancelled_by_customer']);
    }

    /** The event of a space announces its barbershop: it is what a worker without a request uses to scope itself (MT-02). */
    public function test_the_event_carries_the_barbershop(): void
    {
        Event::fake([SpaceReleased::class]);
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        Event::assertDispatched(SpaceReleased::class, fn (SpaceReleased $event): bool => $event->barbershopId === $this->puraNavaja && $event->collaboratorId === $this->daniela);
    }

    /** An event of another barbershop cannot offer the space of this one: the collaborator is not found there and nothing happens (MT-02, MT-03). */
    public function test_an_event_of_another_barbershop_offers_nothing_here(): void
    {
        $event = new SpaceReleased($this->fadeLab, $this->bookedReservation, $this->daniela, $this->instant('2030-03-05 10:00:00'), $this->instant('2030-03-05 10:50:00'));

        $this->app->make(OfferSpaceReleased::class)->handle($event);

        $this->assertSame([], $this->offerRows());
        $this->assertSame('waiting', $this->entryStatus($this->anaEntry));
    }
}
