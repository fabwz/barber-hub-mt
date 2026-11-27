<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations\AssistedClients;

use Illuminate\Support\Facades\DB;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * The space that an assisted reservation frees when the owner cancels or moves it is offered to the first person of the waitlist, as the space of
 * any other reservation (RF-12, RF-31, CA-07, LE-02), and the assisted client, who has no role, waits in no line and is taken out of none (LE-06).
 * Today is Friday 1 March 2030, 8:00.
 */
final class AssistedReservationWaitlistTest extends TestCase
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

    /** Register an assisted reservation of Daniela at 14:00 as the owner and return its identifier. */
    private function registerAssistedAtTwo(): string
    {
        $this->actAs($this->owner);

        return (string) $this->postJson($this->reservationsUrl($this->puraNavaja).'/asistida', [
            ...$this->booking(startsAt: '2030-03-05T14:00:00-06:00'),
            'cliente_nombre' => 'Rosa Campos',
            'cliente_telefono' => '8744 0021',
        ])->assertCreated()->json('data.id');
    }

    /** Registering an assisted reservation does not touch the line of the day: nobody leaves it and no offer is made (LE-06). */
    public function test_registering_leaves_the_line_as_it_was(): void
    {
        $this->registerAssistedAtTwo();

        $this->assertSame(['waiting', 'waiting', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
        $this->assertSame([], $this->offerRows());
    }

    /** When the owner cancels it, the first of the line receives an offer for the whole space it held, cleaning included (CA-07, LE-02, LE-04). */
    public function test_the_cancellation_offers_the_space_to_the_first_in_the_line(): void
    {
        $id = $this->registerAssistedAtTwo();

        $this->postJson($this->cancelUrlOf($id))->assertOk()->assertJsonPath('data.estado', 'cancelada_por_barberia');

        $offers = $this->offerRows();
        $this->assertCount(1, $offers);
        $this->assertSame($this->anaEntry, $offers[0]->waitlist_entry_id);
        $this->assertSame($this->daniela, $offers[0]->collaborator_id);
        $this->assertSame(['2030-03-05 14:00:00-06', '2030-03-05 14:50:00-06'], [$offers[0]->starts_at, $offers[0]->ends_at]);
        $this->assertSame('offered', $this->entryStatus($this->anaEntry));
    }

    /** When the owner moves it, the space it left is offered and the new one is not (RF-31). */
    public function test_moving_it_offers_the_space_it_left(): void
    {
        $id = $this->registerAssistedAtTwo();

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$id}", ['inicio' => '2030-03-05T16:00:00-06:00'])->assertOk();

        $offers = $this->offerRows();
        $this->assertCount(1, $offers);
        $this->assertSame('2030-03-05 14:00:00-06', $offers[0]->starts_at);
        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('2030-03-05 16:00:00-06', DB::table('reservations')->where('id', $id)->value('starts_at'));
    }

    /** The person of the line who accepts the freed space gets a reservation of their own, the assisted one stays cancelled (LE-04). */
    public function test_the_offer_can_be_accepted_by_the_person_waiting(): void
    {
        $id = $this->registerAssistedAtTwo();
        $this->postJson($this->cancelUrlOf($id))->assertOk();
        $offer = $this->offerRows()[0];

        $this->actAs($this->ana);
        $this->postJson("/api/v1/barberias/{$this->puraNavaja}/ofertas/{$offer->id}/aceptacion")->assertOk();

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('cancelled_by_barbershop', DB::table('reservations')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('reservations')->where('customer_membership_id', $this->anaMembership)->where('starts_at', '2030-03-05 14:00:00-06')->count());
    }
}
