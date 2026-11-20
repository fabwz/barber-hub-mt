<?php

declare(strict_types=1);

namespace Tests\Support\Waitlist;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Reservations\Reservations\Domain\Events\SpaceReleased;
use Src\Shared\Domain\Contracts\Clock;
use stdClass;
use Tests\Support\Shared\MutableClock;

/**
 * What the tests of the offers against PostgreSQL share: the two barbershops of the bookings, a clock the test moves, the waitlist switched on
 * in Pura Navaja, a customer who booked Daniela on Tuesday 5 March at 10:00 for "Degradado" (45 minutes, held until 10:50), and three people who
 * wait for "Degradado" that Tuesday, Ana first. It goes with SeedsBookableBarbershops, SeedsReservations and SeedsWaitlist, whose helpers it uses.
 */
trait SeedsOffers
{
    private MutableClock $clock;

    private string $booker;

    private string $bookerMembership;

    private string $ana;

    private string $anaMembership;

    private string $luis;

    private string $luisMembership;

    private string $bea;

    private string $beaMembership;

    private string $bookedReservation;

    private string $anaEntry;

    private string $luisEntry;

    private string $beaEntry;

    /** Seed the barbershops, the switch, the reservation to cancel and the three rows of the line. */
    private function seedOffers(): void
    {
        $this->seedBookableBarbershops();
        $this->clock = new MutableClock($this->instant('2030-03-01 08:00:00'));
        $this->app->instance(Clock::class, $this->clock);
        $this->scopeQueriesTo($this->puraNavaja);
        DB::table('barbershops')->where('id', $this->puraNavaja)->update(['waitlist_enabled' => true]);
        [$this->booker, $this->bookerMembership] = $this->insertCustomer($this->puraNavaja, 'Carla Booker');
        [$this->ana, $this->anaMembership] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [$this->luis, $this->luisMembership] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        [$this->bea, $this->beaMembership] = $this->insertCustomer($this->puraNavaja, 'Bea Solano');
        $this->bookedReservation = $this->insertReservation($this->puraNavaja, $this->daniela, $this->degradado, $this->bookerMembership, self::TUESDAY_TEN);
        $this->anaEntry = $this->insertWaitlistEntry($this->puraNavaja, $this->anaMembership, $this->degradado, joinedAt: '2030-03-01 08:00:00-06');
        $this->luisEntry = $this->insertWaitlistEntry($this->puraNavaja, $this->luisMembership, $this->degradado, joinedAt: '2030-03-01 08:01:00-06');
        $this->beaEntry = $this->insertWaitlistEntry($this->puraNavaja, $this->beaMembership, $this->degradado, joinedAt: '2030-03-01 08:02:00-06');
    }

    /** An instant in Costa Rica. */
    private function instant(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /** Move the clock of the test. */
    private function setNow(string $moment): void
    {
        $this->clock->moveTo($this->instant($moment));
    }

    /** Where the cancellation of a reservation is served. */
    private function cancelUrlOf(string $reservationId): string
    {
        return $this->reservationsUrl($this->puraNavaja)."/{$reservationId}/cancelacion";
    }

    /** The space Daniela frees when the reservation of the test is cancelled, as the event that announces it carries it. */
    private function releasedSpace(string $from = '10:00', string $to = '10:50'): SpaceReleased
    {
        return new SpaceReleased(
            $this->puraNavaja,
            $this->bookedReservation,
            $this->daniela,
            $this->instant("2030-03-05 {$from}:00"),
            $this->instant("2030-03-05 {$to}:00"),
        );
    }

    /**
     * The offers of Pura Navaja as rows, from the oldest.
     *
     * @return list<stdClass>
     */
    private function offerRows(): array
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return array_values(DB::table('waitlist_offers')->orderBy('created_at')->orderBy('id')->get()->all());
    }

    /** The status of a row of the line of Pura Navaja. */
    private function entryStatus(string $entryId): string
    {
        $this->scopeQueriesTo($this->puraNavaja);

        return (string) DB::table('waitlist_entries')->where('id', $entryId)->value('status');
    }

    /** Insert a pending offer straight into the table, as Pura Navaja, and return its identifier. */
    private function insertOfferRow(string $entryId, string $collaboratorId, string $from, string $to, string $createdAt = '2030-03-01 08:00:00-06', string $status = 'pending'): string
    {
        $id = (string) Str::uuid7();
        $this->scopeQueriesTo($this->puraNavaja);
        $createdUtc = $this->instant(substr($createdAt, 0, 19));
        DB::table('waitlist_offers')->insert([
            'id' => $id,
            'barbershop_id' => $this->puraNavaja,
            'waitlist_entry_id' => $entryId,
            'collaborator_id' => $collaboratorId,
            'starts_at' => "2030-03-05 {$from}:00-06",
            'ends_at' => "2030-03-05 {$to}:00-06",
            'expires_at' => $createdUtc->modify('+15 minutes')->format('Y-m-d H:i:sP'),
            'status' => $status,
            'responded_at' => in_array($status, ['accepted', 'rejected'], true) ? $createdUtc->modify('+5 minutes')->format('Y-m-d H:i:sP') : null,
            'created_at' => $createdUtc->format('Y-m-d H:i:sP'),
        ]);

        return $id;
    }
}
