<?php

declare(strict_types=1);

namespace Tests\Feature\Waitlist;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;
use Src\Waitlist\Entries\Infrastructure\Persistence\Repositories\EloquentWaitlistEntryRepository;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\Support\Reservations\SeedsBookableBarbershops;
use Tests\Support\Reservations\SeedsReservations;
use Tests\Support\Shared\FixedBarbershopContext;
use Tests\Support\Waitlist\SeedsWaitlist;
use Tests\TestCase;

/** The repository of the rows of the waitlist against PostgreSQL: it writes and reads what the aggregate holds, and every method filters by the barbershop (MT-02, LE-01, LE-02). */
final class EloquentWaitlistEntryRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;
    use SeedsBookableBarbershops;
    use SeedsReservations;
    use SeedsWaitlist;

    private string $ana;

    private string $bea;

    private EloquentWaitlistEntryRepository $repository;

    /** Pura Navaja is the current barbershop; Ana is its customer and Bea one of Fade Lab. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedBookableBarbershops();
        [, $this->ana] = $this->insertCustomer($this->puraNavaja, 'Ana Rojas');
        [, $this->bea] = $this->insertCustomer($this->fadeLab, 'Bea Mora');
        $this->repository = new EloquentWaitlistEntryRepository(new FixedBarbershopContext(new BarbershopId($this->puraNavaja)));
        $this->scopeQueriesTo($this->puraNavaja);
    }

    /** A new row is stored with every value of the aggregate and read back the same, with its instant intact, to the second as every timestamp of the schema (LE-02). */
    public function test_a_row_is_stored_and_read_back(): void
    {
        $joined = new DateTimeImmutable('2030-03-01 08:00:00', new DateTimeZone('America/Costa_Rica'));
        $entry = WaitlistEntry::join($this->repository->nextId(), new CustomerMembershipId($this->ana), new ServiceId($this->degradado), new CollaboratorId($this->daniela), DesiredDate::of('2030-03-05'), $joined);

        $this->repository->save($entry);

        $read = $this->repository->activeOfCustomer(new CustomerMembershipId($this->ana))[0];
        $this->assertSame($entry->id->value, $read->id->value);
        $this->assertSame($this->daniela, $read->collaboratorId?->value);
        $this->assertSame('2030-03-05', $read->desiredDate->value);
        $this->assertSame(WaitlistStatus::Waiting, $read->status);
        $this->assertSame($joined->format('Y-m-d H:i:s'), $read->joinedAt->setTimezone(new DateTimeZone('America/Costa_Rica'))->format('Y-m-d H:i:s'));
        $this->assertSame($this->puraNavaja, DB::table('waitlist_entries')->where('id', $entry->id->value)->value('barbershop_id'));
    }

    /** Leaving is saved as a change of status, and the row stops being active. */
    public function test_leaving_is_saved_and_the_row_stops_counting(): void
    {
        $customer = new CustomerMembershipId($this->ana);
        $entry = WaitlistEntry::join($this->repository->nextId(), $customer, new ServiceId($this->degradado), null, DesiredDate::of('2030-03-05'), new DateTimeImmutable('2030-03-01 08:00:00'));
        $this->repository->save($entry);
        $this->assertSame(1, $this->repository->countActiveOfCustomer($customer));

        $found = $this->repository->findOfCustomerForUpdate($entry->id, $customer);
        $found?->leave(new DateTimeImmutable('2030-03-02 08:00:00'));
        $this->repository->save($found ?? $entry);

        $this->assertSame(0, $this->repository->countActiveOfCustomer($customer));
        $this->assertSame('left', DB::table('waitlist_entries')->where('id', $entry->id->value)->value('status'));
    }

    /** The active rows come in the order they joined, the identifier breaking a tie; fulfilled and left rows are not listed (LE-02). */
    public function test_active_rows_come_in_the_order_they_joined(): void
    {
        $late = $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, date: '2030-03-06', joinedAt: '2030-03-01 10:00:00-06');
        $first = $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, date: '2030-03-07', status: 'offered', joinedAt: '2030-03-01 07:00:00-06');
        $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, date: '2030-03-08', status: 'left');
        $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, date: '2030-03-09', status: 'fulfilled');

        $ids = array_map(fn (WaitlistEntry $entry): string => $entry->id->value, $this->repository->activeOfCustomer(new CustomerMembershipId($this->ana)));

        $this->assertSame([$first, $late], $ids);
    }

    /** The same combination is detected, with "any collaborator" matching only "any"; a left row does not count. */
    public function test_a_duplicate_is_an_active_row_with_the_same_combination(): void
    {
        $customer = new CustomerMembershipId($this->ana);
        $service = new ServiceId($this->degradado);
        $date = DesiredDate::of('2030-03-06');
        $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, date: '2030-03-06');
        $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, $this->daniela, '2030-03-07');
        $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, $this->kevin, '2030-03-08', 'left');

        $this->assertTrue($this->repository->hasActiveFor($customer, $service, null, $date));
        $this->assertFalse($this->repository->hasActiveFor($customer, $service, new CollaboratorId($this->daniela), $date));
        $this->assertTrue($this->repository->hasActiveFor($customer, $service, new CollaboratorId($this->daniela), DesiredDate::of('2030-03-07')));
        $this->assertFalse($this->repository->hasActiveFor($customer, $service, null, DesiredDate::of('2030-03-07')));
        $this->assertFalse($this->repository->hasActiveFor($customer, $service, new CollaboratorId($this->kevin), DesiredDate::of('2030-03-08')));
    }

    /** A row of another customer, or of another barbershop, is not found, counted or listed (CL-04, MT-02, MT-03). */
    public function test_only_the_rows_of_the_customer_in_the_barbershop_are_reached(): void
    {
        [, $luis] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $luisRow = $this->insertWaitlistEntry($this->puraNavaja, $luis, $this->degradado);
        $beaRow = $this->insertWaitlistEntry($this->fadeLab, $this->bea, $this->corte);
        $this->scopeQueriesTo($this->puraNavaja);
        $ana = new CustomerMembershipId($this->ana);

        $this->assertNull($this->repository->findOfCustomerForUpdate(new WaitlistEntryId($luisRow), $ana));
        $this->assertNull($this->repository->findOfCustomerForUpdate(new WaitlistEntryId($beaRow), new CustomerMembershipId($this->bea)));
        $this->assertSame(0, $this->repository->countActiveOfCustomer($ana));
        $this->assertSame(0, $this->repository->countActiveOfCustomer(new CustomerMembershipId($this->bea)));
        $this->assertSame([], $this->repository->activeOfCustomer($ana));
    }
}
