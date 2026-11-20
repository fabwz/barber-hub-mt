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

    /** The line of a day lists the rows that wait for the collaborator or for any, in the order they joined, and nothing else (LE-02, LE-03). */
    public function test_the_line_of_a_day_lists_the_rows_that_wait_for_the_collaborator_or_any(): void
    {
        [, $luis] = $this->insertCustomer($this->puraNavaja, 'Luis Mora');
        $late = $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, date: '2030-03-05', joinedAt: '2030-03-01 10:00:00-06');
        $daniela = $this->insertWaitlistEntry($this->puraNavaja, $luis, $this->degradado, $this->daniela, '2030-03-05', joinedAt: '2030-03-01 09:00:00-06');
        $any = $this->insertWaitlistEntry($this->puraNavaja, $luis, $this->degradado, date: '2030-03-05', joinedAt: '2030-03-01 08:00:00-06');
        $this->insertWaitlistEntry($this->puraNavaja, $luis, $this->degradado, $this->kevin, '2030-03-05', joinedAt: '2030-03-01 07:00:00-06');
        $this->insertWaitlistEntry($this->puraNavaja, $luis, $this->degradado, date: '2030-03-06', joinedAt: '2030-03-01 07:00:00-06');
        $this->insertWaitlistEntry($this->puraNavaja, $luis, $this->degradado, date: '2030-03-05', status: 'offered', joinedAt: '2030-03-01 06:00:00-06');
        $this->insertWaitlistEntry($this->puraNavaja, $luis, $this->degradado, date: '2030-03-05', status: 'left', joinedAt: '2030-03-01 06:00:00-06');
        $this->insertWaitlistEntry($this->fadeLab, $this->bea, $this->corte, date: '2030-03-05', joinedAt: '2030-03-01 05:00:00-06');
        $this->scopeQueriesTo($this->puraNavaja);

        $ids = array_map(fn (WaitlistEntry $entry): string => $entry->id->value, $this->repository->waitingFor(DesiredDate::of('2030-03-05'), new CollaboratorId($this->daniela)));

        $this->assertSame([$any, $daniela, $late], $ids);
    }

    /** Two rows of the same instant come out by identifier, so the order is the same every time (LE-02). */
    public function test_a_tie_in_the_line_is_broken_by_the_identifier(): void
    {
        $first = $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, date: '2030-03-05', joinedAt: '2030-03-01 08:00:00-06');
        $second = $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, $this->daniela, '2030-03-05', joinedAt: '2030-03-01 08:00:00-06');
        $this->scopeQueriesTo($this->puraNavaja);

        $ids = array_map(fn (WaitlistEntry $entry): string => $entry->id->value, $this->repository->waitingFor(DesiredDate::of('2030-03-05'), new CollaboratorId($this->daniela)));

        $this->assertSame([$first, $second], $ids);
    }

    /** A row is found and locked by its identifier among those of the barbershop, whoever owns it; one of another barbershop is not found (MT-02, MT-03). */
    public function test_a_row_is_found_by_its_identifier_in_the_barbershop(): void
    {
        $own = $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado);
        $foreign = $this->insertWaitlistEntry($this->fadeLab, $this->bea, $this->corte);
        $this->scopeQueriesTo($this->puraNavaja);

        $this->assertSame($own, $this->repository->findForUpdate(new WaitlistEntryId($own))?->id->value);
        $this->assertNull($this->repository->findForUpdate(new WaitlistEntryId($foreign)));
    }

    /** An offer and the return to the line are saved as changes of status, and the row keeps the place it joined with (RF-31, LE-05). */
    public function test_an_offer_and_the_return_to_the_line_are_saved(): void
    {
        $id = $this->insertWaitlistEntry($this->puraNavaja, $this->ana, $this->degradado, joinedAt: '2030-03-01 08:00:00-06');
        $this->scopeQueriesTo($this->puraNavaja);

        $entry = $this->repository->findForUpdate(new WaitlistEntryId($id)) ?? $this->fail('The row was not found.');
        $entry->offer(new DateTimeImmutable('2030-03-05 11:00:00'));
        $this->repository->save($entry);
        $this->assertSame('offered', DB::table('waitlist_entries')->where('id', $id)->value('status'));

        $entry = $this->repository->findForUpdate(new WaitlistEntryId($id)) ?? $this->fail('The row was not found.');
        $entry->returnToLine(new DateTimeImmutable('2030-03-05 11:15:00'));
        $this->repository->save($entry);

        $this->assertSame('waiting', DB::table('waitlist_entries')->where('id', $id)->value('status'));
        $this->assertSame('2030-03-01 08:00:00-06', DB::table('waitlist_entries')->where('id', $id)->value('joined_at'));
    }
}
