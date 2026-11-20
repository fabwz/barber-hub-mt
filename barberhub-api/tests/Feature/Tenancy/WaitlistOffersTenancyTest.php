<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\DB;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Waitlist\Offers\Infrastructure\Listeners\OfferSpaceReleased;
use stdClass;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * The offers belong to a barbershop (MT-02, MT-03, RNF-06): a space freed in one only reaches the line of that one, the barbershop of an event
 * decides the scope of the worker and never the data it carries, and what an offer holds in one barbershop is invisible in the other. The offers
 * have no route yet (the customer answers them in #94), so there is no row in the permission matrix; this suite is what proves their isolation
 * together with the schema test and the registry of resources.
 */
final class WaitlistOffersTenancyTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $fadeLabEntry;

    /** Both barbershops have someone waiting for the same Tuesday at the same hour, Fade Lab for Mauricio. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedOffers();
        [, $fadeLabMembership] = $this->insertCustomer($this->fadeLab, 'Dora Quesada');
        DB::table('barbershops')->where('id', $this->fadeLab)->update(['waitlist_enabled' => true]);
        $this->fadeLabEntry = $this->insertWaitlistEntry($this->fadeLab, $fadeLabMembership, $this->corte, joinedAt: '2030-03-01 07:00:00-06');
    }

    /** A space freed in Pura Navaja offers to its own line and leaves the line of Fade Lab alone, though it joined earlier. */
    public function test_a_release_only_reaches_the_line_of_its_own_barbershop(): void
    {
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertSame([$this->anaEntry], array_map(fn (stdClass $offer): string => $offer->waitlist_entry_id, $this->offerRows()));
        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame(0, DB::table('waitlist_offers')->count());
        $this->assertSame('waiting', DB::table('waitlist_entries')->where('id', $this->fadeLabEntry)->value('status'));
    }

    /** The barbershop in the event decides what the worker reaches: with the one of Fade Lab and a collaborator of Pura Navaja nothing is found. */
    public function test_the_barbershop_of_the_event_decides_the_scope(): void
    {
        $this->freeTheSpace();
        $event = new SpaceReleased($this->fadeLab, $this->bookedReservation, $this->daniela, $this->instant('2030-03-05 10:00:00'), $this->instant('2030-03-05 10:50:00'));

        $this->app->make(OfferSpaceReleased::class)->handle($event);

        $this->assertSame([], $this->offerRows());
        $this->scopeQueriesTo($this->fadeLab);
        $this->assertSame(0, DB::table('waitlist_offers')->count());
        $this->assertSame('waiting', DB::table('waitlist_entries')->where('id', $this->fadeLabEntry)->value('status'));
    }

    /** The space held by an offer of Pura Navaja is invisible to Fade Lab: the availability of its collaborator at the same hour is untouched (DI-05, MT-02). */
    public function test_an_offer_holds_nothing_in_another_barbershop(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $query = http_build_query(['servicio' => $this->corte, 'fecha' => '2030-03-05', 'barbero' => $this->mauricio]);
        $spaces = $this->getJson("/api/v1/barberias/{$this->fadeLab}/disponibilidad?{$query}")->assertOk()->json('data.barberos.0.espacios');

        $this->assertContains('10:00', array_map(fn (array $space): string => substr($space['inicio'], 11, 5), $spaces));
    }

    /** Each offer carries the barbershop of its row, and its foreign keys only reach rows of that same barbershop, as the reader of the offers filters by it (MT-04). */
    public function test_an_offer_carries_the_barbershop_of_its_row(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame([$this->puraNavaja], DB::table('waitlist_offers')->pluck('barbershop_id')->unique()->values()->all());
        $this->assertSame($this->puraNavaja, DB::table('waitlist_entries')->where('id', DB::table('waitlist_offers')->value('waitlist_entry_id'))->value('barbershop_id'));
    }

    /** Cancel the reservation of the test straight in the table, so the space is free when a listener runs without a request. */
    private function freeTheSpace(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('reservations')->where('id', $this->bookedReservation)->update(['status' => 'cancelled_by_customer']);
    }
}
