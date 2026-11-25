<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Entries\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Identity\Memberships\Domain\Exceptions\StaffCannotBeCustomerException;
use Src\Shared\Domain\ValueObjects\TimeInterval;
use Src\Waitlist\Entries\Application\DTOs\JoinWaitlistCommand;
use Src\Waitlist\Entries\Application\UseCases\JoinWaitlist;
use Src\Waitlist\Entries\Application\UseCases\LeaveWaitlist;
use Src\Waitlist\Entries\Application\UseCases\ListMyWaitlistEntries;
use Src\Waitlist\Entries\Application\UseCases\NameEntry;
use Src\Waitlist\Entries\Domain\Exceptions\CollaboratorNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\DesiredDateInPastException;
use Src\Waitlist\Entries\Domain\Exceptions\DuplicateEntryException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\ServiceNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\SpacesAvailableException;
use Src\Waitlist\Entries\Domain\Exceptions\WaitlistDisabledException;
use Src\Waitlist\Entries\Domain\Exceptions\WaitlistLimitReachedException;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\OpenSpace;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;
use Tests\Support\Shared\RecordingTransactionRunner;
use Tests\Support\Waitlist\InMemoryWaitlistEntryRepository;
use Tests\Support\Waitlist\InMemoryWaitlistMemberships;
use Tests\Support\Waitlist\InMemoryWaitlistPorts;
use Throwable;

/** The use cases of the waitlist, without a framework or a database: RF-30, LE-01, LE-02, CL-04 and UP-09. */
final class WaitlistUseCasesTest extends TestCase
{
    private const string ANA = '0192f0c4-1111-7000-8000-000000000001';

    private const string LUIS = '0192f0c4-1111-7000-8000-000000000002';

    private const string SERVICE = '0192f0c4-4444-7000-8000-000000000001';

    private const string DANIELA = '0192f0c4-5555-7000-8000-000000000001';

    private InMemoryWaitlistEntryRepository $entries;

    private InMemoryWaitlistMemberships $memberships;

    private InMemoryWaitlistPorts $ports;

    private RecordingTransactionRunner $transaction;

    private JoinWaitlist $join;

    /** A barbershop with the waitlist on, one service, one collaborator and no free spaces. */
    protected function setUp(): void
    {
        $this->entries = new InMemoryWaitlistEntryRepository;
        $this->memberships = new InMemoryWaitlistMemberships;
        $this->ports = new InMemoryWaitlistPorts;
        $this->ports->services = [self::SERVICE];
        $this->ports->collaborators = [self::DANIELA];
        $this->transaction = new RecordingTransactionRunner;
        $this->join = new JoinWaitlist($this->entries, $this->ports, $this->ports, $this->ports, $this->memberships, $this->transaction);
    }

    /** The row is created waiting, with the instant of the request as its place and under the role of the person, created with it (UP-09, LE-02). */
    public function test_joining_creates_the_role_and_a_waiting_row(): void
    {
        $now = self::at('2030-03-01 08:00:00');

        $entry = $this->join->execute($this->command(self::ANA, '2030-03-06'), $now);

        $this->assertSame(WaitlistStatus::Waiting, $entry->status);
        $this->assertSame($now, $entry->joinedAt);
        $this->assertNull($entry->collaboratorId);
        $this->assertSame($this->memberships->find(self::ANA), $entry->customerMembershipId->value);
        $this->assertSame(1, $this->memberships->created());
        $this->assertSame(1, $this->entries->locks());
    }

    /** The rows of a customer come out in the order they joined, whatever the order they were created in (LE-02). */
    public function test_rows_are_listed_in_the_order_they_joined(): void
    {
        $this->join->execute($this->command(self::ANA, '2030-03-08'), self::at('2030-03-01 09:00:00'));
        $this->join->execute($this->command(self::ANA, '2030-03-06'), self::at('2030-03-01 07:00:00'));
        $this->join->execute($this->command(self::ANA, '2030-03-07'), self::at('2030-03-01 08:00:00'));

        $dates = array_map(fn ($view): string => $view->entry->desiredDate->value, $this->list()->execute(self::ANA));

        $this->assertSame(['2030-03-06', '2030-03-07', '2030-03-08'], $dates);
    }

    /** The fourth active row is refused; the others stay (LE-01). */
    public function test_the_fourth_active_row_is_refused(): void
    {
        foreach (['2030-03-06', '2030-03-07', '2030-03-08'] as $date) {
            $this->join->execute($this->command(self::ANA, $date), self::at('2030-03-01 08:00:00'));
        }

        try {
            $this->join->execute($this->command(self::ANA, '2030-03-09'), self::at('2030-03-01 08:00:00'));
            $this->fail('The fourth row was accepted.');
        } catch (WaitlistLimitReachedException) {
            $this->assertSame(3, $this->entries->count());
        }
    }

    /** Leaving frees a place of the limit, and another customer is not counted (LE-01, CL-04). */
    public function test_a_left_row_frees_a_place_and_the_limit_is_per_customer(): void
    {
        $now = self::at('2030-03-01 08:00:00');
        $first = $this->join->execute($this->command(self::ANA, '2030-03-06'), $now);
        $this->join->execute($this->command(self::ANA, '2030-03-07'), $now);
        $this->join->execute($this->command(self::ANA, '2030-03-08'), $now);
        $leave = new LeaveWaitlist($this->entries, $this->memberships, $this->transaction);

        $leave->execute(self::ANA, $first->id->value, $now);

        $this->join->execute($this->command(self::ANA, '2030-03-09'), $now);
        $this->join->execute($this->command(self::LUIS, '2030-03-09'), $now);
        $this->assertSame(5, $this->entries->count());
    }

    /** With free spaces the person does not join, the error carries the first three in order of start, and the transaction is rolled back (LE-01, UP-09). */
    public function test_free_spaces_are_shown_and_nothing_is_created(): void
    {
        $this->ports->spaces = array_map(
            fn (int $hour): OpenSpace => new OpenSpace(new CollaboratorId(self::DANIELA), TimeInterval::between(self::at("2030-03-05 {$hour}:00:00"), self::at("2030-03-05 {$hour}:45:00"))),
            [9, 10, 11, 14],
        );

        try {
            $this->join->execute($this->command(self::ANA, '2030-03-05'), self::at('2030-03-01 08:00:00'));
            $this->fail('The person joined although there were free spaces.');
        } catch (SpacesAvailableException $exception) {
            $this->assertCount(3, $exception->spaces());
            $this->assertSame('espacios', array_key_first($exception->problemMembers()));
            $this->assertSame('conflicto', $exception->rule());
        }

        $this->assertSame(0, $this->entries->count());
        $this->assertSame(1, $this->transaction->rolledBack());
    }

    /** The same combination twice is refused; a different collaborator or day is another row. */
    public function test_the_same_combination_twice_is_refused(): void
    {
        $now = self::at('2030-03-01 08:00:00');
        $this->join->execute($this->command(self::ANA, '2030-03-06'), $now);
        $this->join->execute($this->command(self::ANA, '2030-03-06', self::DANIELA), $now);

        $this->expectException(DuplicateEntryException::class);

        $this->join->execute($this->command(self::ANA, '2030-03-06'), $now);
    }

    /** With the waitlist off, a day that has passed, or an unknown service or collaborator, nothing is asked, locked or created (RF-09, MT-03). */
    public function test_refusals_before_any_write(): void
    {
        $now = self::at('2030-03-01 08:00:00');
        $cases = [
            [WaitlistDisabledException::class, false, $this->command(self::ANA, '2030-03-06')],
            [DesiredDateInPastException::class, true, $this->command(self::ANA, '2030-02-28')],
            [ServiceNotFoundException::class, true, new JoinWaitlistCommand(self::ANA, '0192f0c4-4444-7000-8000-000000000009', null, '2030-03-06')],
            [CollaboratorNotFoundException::class, true, new JoinWaitlistCommand(self::ANA, self::SERVICE, '0192f0c4-5555-7000-8000-000000000009', '2030-03-06')],
        ];

        foreach ($cases as [$exception, $enabled, $command]) {
            $this->ports->enabled = $enabled;

            try {
                $this->join->execute($command, $now);
                $this->fail("{$exception} was not thrown.");
            } catch (Throwable $thrown) {
                $this->assertInstanceOf($exception, $thrown);
            }
        }

        $this->assertSame(0, $this->transaction->runs());
        $this->assertSame(0, $this->memberships->created());
        $this->assertSame(0, $this->entries->count());
        $this->assertSame(0, $this->ports->asked);
    }

    /** The staff of the barbershop cannot join: the role is refused and nothing is written (UP-09). */
    public function test_the_staff_cannot_join(): void
    {
        $this->memberships->makeStaff(self::ANA);

        try {
            $this->join->execute($this->command(self::ANA, '2030-03-06'), self::at('2030-03-01 08:00:00'));
            $this->fail('The staff joined.');
        } catch (StaffCannotBeCustomerException) {
            $this->assertSame(0, $this->entries->count());
        }
    }

    /** A customer only leaves their own row: the row of another, an unknown one and a person with no role get the same not-found (CL-04). */
    public function test_leaving_is_only_for_the_own_row(): void
    {
        $now = self::at('2030-03-01 08:00:00');
        $mine = $this->join->execute($this->command(self::ANA, '2030-03-06'), $now);
        $this->join->execute($this->command(self::LUIS, '2030-03-06'), $now);
        $leave = new LeaveWaitlist($this->entries, $this->memberships, $this->transaction);

        foreach ([[self::LUIS, $mine->id->value], [self::ANA, '0192f0c4-3333-7000-8000-000000000999'], ['0192f0c4-1111-7000-8000-000000000999', $mine->id->value]] as [$user, $entry]) {
            try {
                $leave->execute($user, $entry, $now);
                $this->fail('A row that is not theirs was left.');
            } catch (EntryNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(WaitlistStatus::Waiting, $this->entries->findOfCustomerForUpdate(new WaitlistEntryId($mine->id->value), $mine->customerMembershipId)?->status);
        $leave->execute(self::ANA, $mine->id->value, $now);
        $this->assertSame(WaitlistStatus::Left, $mine->status);
    }

    /** A person with no role has an empty list and no role is created by asking (RD-18). */
    public function test_listing_without_a_role_is_empty_and_creates_nothing(): void
    {
        $this->assertSame([], $this->list()->execute(self::ANA));
        $this->assertSame(0, $this->memberships->created());
        $this->assertSame(0, $this->ports->namesRead);
    }

    /** A row for a collaborator carries their name and a row for any carries none; one read names the whole list, never one per row. */
    public function test_rows_carry_the_name_of_their_collaborator_with_one_read(): void
    {
        $this->ports->names = [self::DANIELA => 'Daniela', 'otro' => 'Otro'];
        $this->join->execute($this->command(self::ANA, '2030-03-06', self::DANIELA), self::at('2030-03-01 07:00:00'));
        $this->join->execute($this->command(self::ANA, '2030-03-07'), self::at('2030-03-01 08:00:00'));
        $this->join->execute($this->command(self::ANA, '2030-03-08', self::DANIELA), self::at('2030-03-01 09:00:00'));

        $names = array_map(fn ($view): ?string => $view->collaboratorName, $this->list()->execute(self::ANA));

        $this->assertSame(['Daniela', null, 'Daniela'], $names);
        $this->assertSame(1, $this->ports->namesRead);
    }

    /** Rows that ask for any collaborator need no name, so nothing is read; a collaborator that is no longer known has none. */
    public function test_rows_without_a_collaborator_read_no_names_and_an_unknown_one_has_none(): void
    {
        $this->join->execute($this->command(self::ANA, '2030-03-06'), self::at('2030-03-01 07:00:00'));

        $this->assertSame([null], array_map(fn ($view): ?string => $view->collaboratorName, $this->list()->execute(self::ANA)));
        $this->assertSame(0, $this->ports->namesRead);

        $this->join->execute($this->command(self::ANA, '2030-03-07', self::DANIELA), self::at('2030-03-01 08:00:00'));

        $this->assertSame([null, null], array_map(fn ($view): ?string => $view->collaboratorName, $this->list()->execute(self::ANA)));
    }

    /** The row just created carries the name of its collaborator too, which is what the answer of joining shows. */
    public function test_a_new_row_is_named_for_the_answer(): void
    {
        $this->ports->names = [self::DANIELA => 'Daniela'];
        $named = new NameEntry($this->ports);

        $withCollaborator = $named->execute($this->join->execute($this->command(self::ANA, '2030-03-06', self::DANIELA), self::at('2030-03-01 07:00:00')));
        $withoutCollaborator = $named->execute($this->join->execute($this->command(self::ANA, '2030-03-07'), self::at('2030-03-01 08:00:00')));

        $this->assertSame('Daniela', $withCollaborator->collaboratorName);
        $this->assertNull($withoutCollaborator->collaboratorName);
        $this->assertSame(1, $this->ports->namesRead);
    }

    private function list(): ListMyWaitlistEntries
    {
        return new ListMyWaitlistEntries($this->entries, $this->memberships, $this->ports);
    }

    private function command(string $user, string $date, ?string $collaborator = null): JoinWaitlistCommand
    {
        return new JoinWaitlistCommand($user, self::SERVICE, $collaborator, $date);
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
