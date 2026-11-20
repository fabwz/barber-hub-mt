<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Offers\Domain\Entities\Offer;
use Src\Waitlist\Offers\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferId;
use Src\Waitlist\Offers\Domain\ValueObjects\OfferStatus;
use Src\Waitlist\Offers\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Offers\Infrastructure\Persistence\Queries\EloquentOfferedSpaceReader;
use Src\Waitlist\Offers\Infrastructure\Persistence\Repositories\EloquentOfferRepository;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/**
 * The repository and the reader of the offers against PostgreSQL: they write and read what the aggregate holds, every method filters by
 * the barbershop (MT-02), and the spaces they report are the ones a pending offer still holds (DI-05, LE-05, LE-06).
 */
final class EloquentOfferRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $anaEntry;

    private string $beaEntry;

    private EloquentOfferRepository $repository;

    private EloquentOfferedSpaceReader $reader;

    /** Pura Navaja is the current barbershop, with Ana waiting in it; Bea waits in Fade Lab. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        [, $ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [, $bea] = $this->insertCustomer($this->fadeLab, 'Bea Mora');
        $this->anaEntry = $this->insertWaitlistEntry($this->puraNavaja, $ana, $this->degradado);
        $this->beaEntry = $this->insertWaitlistEntry($this->fadeLab, $bea, $this->corte);
        $context = new FixedBarbershopContext(new BarbershopId($this->puraNavaja));
        $this->repository = new EloquentOfferRepository($context);
        $this->reader = new EloquentOfferedSpaceReader($context);
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** A new offer is stored with every value of the aggregate and read back the same, to the second as every timestamp of the schema (LE-04). */
    public function test_an_offer_is_stored_and_read_back(): void
    {
        $offer = $this->offer('11:00', '13:00', '14:05');

        $this->repository->save($offer);

        $read = $this->repository->findForUpdate($offer->id);
        $this->assertNotNull($read);
        $this->assertSame($this->anaEntry, $read->entryId->value);
        $this->assertSame($this->daniela, $read->collaboratorId->value);
        $this->assertSame(OfferStatus::Pending, $read->status);
        $this->assertNull($read->respondedAt);
        $this->assertSame('2030-03-05 13:00', $read->interval->start->setTimezone(self::zone())->format('Y-m-d H:i'));
        $this->assertSame('2030-03-05 14:05', $read->interval->end->setTimezone(self::zone())->format('Y-m-d H:i'));
        $this->assertSame('2030-03-05 11:15', $read->expiresAt->setTimezone(self::zone())->format('Y-m-d H:i'));
        $this->assertSame($this->puraNavaja, DB::table('waitlist_offers')->where('id', $offer->id->value)->value('barbershop_id'));
    }

    /** Expiring is saved as a change of status, and the offer stops holding its space. */
    public function test_expiring_is_saved(): void
    {
        $offer = $this->offer('11:00', '13:00', '14:05');
        $this->repository->save($offer);

        $found = $this->repository->findForUpdate($offer->id);
        $found?->expire(self::at('11:15'));
        $this->repository->save($found ?? $offer);

        $this->assertSame(OfferStatus::Expired, $this->repository->findForUpdate($offer->id)?->status);
        $this->assertSame([], $this->reader->between([$this->daniela], self::day(), self::at('11:16')));
    }

    /** An offer of another barbershop is not found, even by its identifier (MT-02, MT-03). */
    public function test_an_offer_of_another_barbershop_is_not_found(): void
    {
        $foreign = $this->insertForeignOffer();

        $this->assertNull($this->repository->findForUpdate($foreign));
        $this->assertFalse($this->repository->hadOfferOverlapping(new WaitlistEntryId($this->beaEntry), new CollaboratorId($this->mauricio), self::day()));
    }

    /** A row had an offer for a space when any offer of it, in any state, shares a minute with it for the same collaborator (LE-05). */
    public function test_a_row_that_had_an_offer_for_the_space_is_recognised(): void
    {
        $offer = $this->offer('11:00', '13:00', '14:05');
        $offer->expire(self::at('11:15'));
        $this->repository->save($offer);
        $entry = new WaitlistEntryId($this->anaEntry);
        $daniela = new CollaboratorId($this->daniela);

        $this->assertTrue($this->repository->hadOfferOverlapping($entry, $daniela, self::space('13:30', '14:00')));
        $this->assertTrue($this->repository->hadOfferOverlapping($entry, $daniela, self::space('12:00', '13:15')));
        $this->assertFalse($this->repository->hadOfferOverlapping($entry, $daniela, self::space('14:05', '15:00')), 'Touching an end is not overlapping.');
        $this->assertFalse($this->repository->hadOfferOverlapping($entry, new CollaboratorId($this->kevin), self::space('13:30', '14:00')));
    }

    /** A person has a pending offer for a space only while it is pending and inside its 15 minutes (LE-06). */
    public function test_a_pending_offer_inside_its_time_blocks_an_overlapping_one(): void
    {
        $this->repository->save($this->offer('11:00', '13:00', '14:05'));
        $entries = [new WaitlistEntryId($this->anaEntry)];

        $this->assertTrue($this->repository->hasPendingOverlapping($entries, self::space('13:30', '14:30'), self::at('11:14:59')));
        $this->assertFalse($this->repository->hasPendingOverlapping($entries, self::space('13:30', '14:30'), self::at('11:15')), 'An offer past its time holds nothing.');
        $this->assertFalse($this->repository->hasPendingOverlapping($entries, self::space('14:05', '15:00'), self::at('11:10')));
        $this->assertFalse($this->repository->hasPendingOverlapping([], self::space('13:30', '14:30'), self::at('11:10')));
    }

    /** The reader gives the spaces held by pending offers inside their time, by collaborator, and nothing from other collaborators or barbershops (DI-05). */
    public function test_the_reader_gives_the_spaces_held_by_pending_offers(): void
    {
        $this->repository->save($this->offer('11:00', '13:00', '14:05'));
        $this->insertForeignOffer();

        $held = $this->reader->between([$this->daniela, $this->kevin], self::day(), self::at('11:14'));

        $this->assertSame([$this->daniela], array_keys($held));
        $this->assertCount(1, $held[$this->daniela]);
        $this->assertSame('13:00', $held[$this->daniela][0]->start->setTimezone(self::zone())->format('H:i'));
        $this->assertSame('14:05', $held[$this->daniela][0]->end->setTimezone(self::zone())->format('H:i'));
        $this->assertSame([], $this->reader->between([$this->kevin], self::day(), self::at('11:14')));
        $this->assertSame([], $this->reader->between([$this->daniela], self::space('14:05', '15:00'), self::at('11:14')), 'A window that only touches the space does not hold it.');
        $this->assertSame([], $this->reader->between([$this->daniela], self::day(), self::at('11:15')), 'At its deadline the offer holds nothing, even if its task did not run yet.');
    }

    /** Build a pending offer to Ana for Daniela, made at the time, for the space between the two times. */
    private function offer(string $madeAt, string $from, string $to): Offer
    {
        return Offer::create(
            $this->repository->nextId(),
            new WaitlistEntryId($this->anaEntry),
            new CollaboratorId($this->daniela),
            self::space($from, $to),
            self::at($madeAt),
        );
    }

    /** Insert an offer of Fade Lab, as its barbershop, and go back to the current one. */
    private function insertForeignOffer(): OfferId
    {
        $id = $this->repository->nextId();
        $this->scopeQueriesTo($this->fadeLab);
        DB::table('waitlist_offers')->insert([
            'id' => $id->value, 'barbershop_id' => $this->fadeLab, 'waitlist_entry_id' => $this->beaEntry, 'collaborator_id' => $this->mauricio,
            'starts_at' => '2030-03-05 13:00:00-06', 'ends_at' => '2030-03-05 14:05:00-06', 'expires_at' => '2030-03-05 11:15:00-06',
            'status' => 'pending', 'created_at' => '2030-03-05 11:00:00-06',
        ]);
        $this->scopeQueriesTo($this->puraNavaja);

        return $id;
    }

    private static function zone(): DateTimeZone
    {
        return new DateTimeZone('America/Costa_Rica');
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable("2030-03-05 {$time}", self::zone());
    }

    private static function space(string $from, string $to): TimeInterval
    {
        return TimeInterval::between(self::at($from), self::at($to));
    }

    /** The whole day. */
    private static function day(): TimeInterval
    {
        return self::space('00:00', '23:59');
    }
}
