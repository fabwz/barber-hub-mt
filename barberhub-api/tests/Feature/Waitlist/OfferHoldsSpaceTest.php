<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Src\Waitlist\Offers\Infrastructure\Jobs\ExpireOfferJob;
use stdClass;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Waitlist\SeedsOffers;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * While an offer is pending its space does not appear free to anybody else: not in the availability, not when another customer books it and not
 * when a reservation is moved into it, and it is public again as soon as the offer expires and the line runs out (DI-05, LE-04, LE-05). The
 * rule lives in the use cases, under the lock of the collaborator, and not in a constraint of the table. Today is Friday 1 March 2030, 8:00.
 */
final class OfferHoldsSpaceTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsOffers;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $danielaUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedOffers();
        $this->danielaUser = (string) DB::table('collaborators')->where('id', $this->daniela)->value('user_id');
    }

    /** Without an offer the space of the cancelled reservation would be free: the availability lists it. */
    public function test_a_freed_space_is_free_when_nobody_waits(): void
    {
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('waitlist_entries')->update(['status' => 'left']);
        $this->actAs($this->booker);

        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->assertContains('10:00', $this->startsOfDaniela());
        $this->assertSame([], $this->offerRows());
    }

    /** While the offer is pending the availability does not list the space, nor any start that would overlap it (DI-05). */
    public function test_the_availability_does_not_list_the_offered_space(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $starts = $this->startsOfDaniela();

        $this->assertNotContains('10:00', $starts);
        $this->assertNotContains('09:30', $starts, 'A service from 9:30 to 10:15 would overlap the space.');
        $this->assertNotContains('10:30', $starts, 'A service from 10:30 would overlap the cleaning of the space.');
        $this->assertContains('09:00', $starts, 'The time before the space is untouched.');
        $this->assertNotContains('10:45', $starts, 'A service from 10:45 would overlap the space until 10:50.');
        $this->assertContains('11:00', $starts, 'The time after the space is untouched.');
    }

    /** Another customer who tries to book the offered space gets the usual conflict, with its alternatives (DI-05, RE-02). */
    public function test_booking_the_offered_space_gets_the_usual_conflict(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();
        $this->actAs($this->danielaUser);
        [$stranger] = $this->insertCustomer($this->puraNavaja, 'Nora Vega');
        $this->actAs($stranger);

        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())
            ->assertStatus(409)
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'espacio-no-disponible'));

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame(0, DB::table('reservations')->where('customer_membership_id', '!=', $this->bookerMembership)->count());
    }

    /** A reservation that is moved into the offered space gets the same conflict, and stays where it was (RE-08, DI-05). */
    public function test_moving_a_reservation_into_the_offered_space_gets_the_conflict(): void
    {
        $other = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->bookerMembership, '2030-03-05T15:00:00-06:00');
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->patchJson($this->reservationsUrl($this->puraNavaja)."/{$other}", ['inicio' => '2030-03-05T10:00:00-06:00'])
            ->assertStatus(409)
            ->assertJsonPath('type', fn (string $type): bool => str_ends_with($type, 'espacio-no-disponible'));

        $this->scopeQueriesTo($this->puraNavaja);
        $this->assertSame('2030-03-05 15:00:00-06', DB::table('reservations')->where('id', $other)->value('starts_at'));
    }

    /** The space of one collaborator does not hide the time of another: Kevin is still free at 10:00 (DI-04). */
    public function test_the_offer_holds_only_the_space_of_its_collaborator(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $kevin = $this->getJson("/api/v1/barberias/{$this->puraNavaja}/disponibilidad?".http_build_query(['servicio' => $this->degradado, 'fecha' => '2030-03-05', 'barbero' => $this->kevin]))
            ->assertOk()
            ->json('data.barberos.0.espacios');

        $this->assertContains('10:00', array_map(fn (array $space): string => substr($space['inicio'], 11, 5), $kevin));
    }

    /** Past its 15 minutes the offer holds nothing even if its task did not run yet, as a temporary reservation past its window (DI-05, LE-05). */
    public function test_an_overdue_offer_holds_nothing_even_before_its_task_runs(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->setNow('2030-03-01 08:14:59');
        $this->assertNotContains('10:00', $this->startsOfDaniela());

        $this->setNow('2030-03-01 08:15:00');
        $this->assertContains('10:00', $this->startsOfDaniela());
    }

    /** When the offer expires and passes to the next person, the space stays held by the new offer, with no instant in which it shows free (LE-05). */
    public function test_the_space_stays_held_while_the_offer_passes_to_the_next_person(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->setNow('2030-03-01 08:15:01');
        $this->runExpiry($this->offerRows()[0]->id);

        $offers = $this->offerRows();
        $this->assertSame(['expired', 'pending'], array_map(fn (stdClass $offer): string => $offer->status, $offers));
        $this->assertSame($this->luisEntry, $offers[1]->waitlist_entry_id);
        $this->assertNotContains('10:00', $this->startsOfDaniela());
        $this->assertSame(['waiting', 'offered', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
    }

    /** When the line runs out, the space is public again and a customer can book it (RF-31, DI-05). */
    public function test_when_the_line_runs_out_the_space_is_public_again_and_can_be_booked(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        foreach (['08:15:01', '08:30:02', '08:45:03'] as $time) {
            $this->setNow("2030-03-01 {$time}");
            $pending = array_values(array_filter($this->offerRows(), fn (stdClass $offer): bool => $offer->status === 'pending'));
            $this->assertCount(1, $pending);
            $this->runExpiry($pending[0]->id);
        }

        $this->assertSame(['expired', 'expired', 'expired'], array_map(fn (stdClass $offer): string => $offer->status, $this->offerRows()));
        $this->assertSame([$this->anaEntry, $this->luisEntry, $this->beaEntry], array_map(fn (stdClass $offer): string => $offer->waitlist_entry_id, $this->offerRows()));
        $this->assertSame(['waiting', 'waiting', 'waiting'], [$this->entryStatus($this->anaEntry), $this->entryStatus($this->luisEntry), $this->entryStatus($this->beaEntry)]);
        $this->assertContains('10:00', $this->startsOfDaniela());
        [$stranger] = $this->insertCustomer($this->puraNavaja, 'Nora Vega');
        $this->actAs($stranger);
        $this->postJson($this->reservationsUrl($this->puraNavaja), $this->booking())->assertCreated();
    }

    /** The task of the queue passes the space within a minute after the 15 minutes: at the second after the deadline and a minute later (LE-05). */
    #[DataProvider('momentsWithinAMinute')]
    public function test_the_task_passes_the_space_within_a_minute_of_the_expiry(string $time): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->setNow("2030-03-01 {$time}");
        $this->runExpiry($this->offerRows()[0]->id);

        $this->assertSame($this->luisEntry, $this->offerRows()[1]->waitlist_entry_id, "At {$time} the line did not move on.");
    }

    /** @return iterable<string, array{string}> */
    public static function momentsWithinAMinute(): iterable
    {
        yield 'the second after the deadline' => ['08:15:01'];
        yield 'half a minute after' => ['08:15:30'];
        yield 'a minute after the deadline' => ['08:16:00'];
    }

    /** A task that runs before the 15 minutes pass changes nothing: the offer keeps holding its space. */
    public function test_a_task_that_runs_early_changes_nothing(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();

        $this->setNow('2030-03-01 08:14:59');
        $this->runExpiry($this->offerRows()[0]->id);

        $this->assertSame(['pending'], array_map(fn (stdClass $offer): string => $offer->status, $this->offerRows()));
        $this->assertSame('offered', $this->entryStatus($this->anaEntry));
    }

    /** A task carrying another barbershop cannot reach the offer: it finds nothing and the offer is left as it was (MT-02, MT-03). */
    public function test_a_task_of_another_barbershop_cannot_reach_the_offer(): void
    {
        $this->actAs($this->booker);
        $this->postJson($this->cancelUrlOf($this->bookedReservation))->assertOk();
        $this->setNow('2030-03-01 09:00:00');

        $this->runExpiry($this->offerRows()[0]->id, $this->fadeLab);

        $this->assertSame(['pending'], array_map(fn (stdClass $offer): string => $offer->status, $this->offerRows()));
    }

    /**
     * The starts that the availability lists for Daniela on Tuesday, as times of the day.
     *
     * @return list<string>
     */
    private function startsOfDaniela(): array
    {
        $query = http_build_query(['servicio' => $this->degradado, 'fecha' => '2030-03-05', 'barbero' => $this->daniela]);
        $spaces = $this->getJson("/api/v1/barberias/{$this->puraNavaja}/disponibilidad?{$query}")->assertOk()->json('data.barberos.0.espacios');

        return array_values(array_map(fn (array $space): string => substr($space['inicio'], 11, 5), $spaces));
    }

    /** Run the task of an offer as a worker does, as the barbershop it carries. */
    private function runExpiry(string $offerId, ?string $barbershopId = null): void
    {
        $job = new ExpireOfferJob($offerId, $barbershopId ?? $this->puraNavaja);
        $this->app->call($job->handle(...));
    }
}
