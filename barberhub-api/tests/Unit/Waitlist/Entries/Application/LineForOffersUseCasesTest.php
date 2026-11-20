<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Entries\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Waitlist\Entries\Application\UseCases\ListWaitingForSpace;
use Src\Waitlist\Entries\Application\UseCases\LockWaitingEntry;
use Src\Waitlist\Entries\Application\UseCases\MarkEntryOffered;
use Src\Waitlist\Entries\Application\UseCases\ReturnEntryToLine;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotFoundException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotWaitingException;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;
use Tests\Support\Waitlist\InMemoryWaitlistEntryRepository;

/** What the offers ask of the line, without a framework or a database: who waits, holding a row, offering it and putting it back (RF-31, LE-02, LE-05, LE-06). */
final class LineForOffersUseCasesTest extends TestCase
{
    private const string ANA = '0192f0c4-2222-7000-8000-000000000001';

    private const string LUIS = '0192f0c4-2222-7000-8000-000000000002';

    private const string DEGRADADO = '0192f0c4-4444-7000-8000-000000000001';

    private const string CORTE = '0192f0c4-4444-7000-8000-000000000002';

    private const string DANIELA = '0192f0c4-5555-7000-8000-000000000001';

    private const string KEVIN = '0192f0c4-5555-7000-8000-000000000002';

    private InMemoryWaitlistEntryRepository $entries;

    protected function setUp(): void
    {
        $this->entries = new InMemoryWaitlistEntryRepository;
    }

    /** The line of a day lists, first to join first, the rows that wait for the collaborator or for any, each with the active rows of its customer (LE-02, LE-06). */
    public function test_the_line_lists_who_waits_in_order_with_the_rows_of_each_customer(): void
    {
        $late = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '10:00');
        $other = $this->join(self::ANA, self::CORTE, null, '2030-03-06', '07:00');
        $first = $this->join(self::LUIS, self::CORTE, self::DANIELA, '2030-03-05', '09:00');
        $this->join(self::LUIS, self::DEGRADADO, self::KEVIN, '2030-03-05', '06:00');

        $line = new ListWaitingForSpace($this->entries)->execute('2030-03-05', self::DANIELA);

        $this->assertSame([$first->id->value, $late->id->value], array_map(fn ($entry): string => $entry->id, $line));
        $this->assertSame(self::DANIELA, $line[0]->collaboratorId);
        $this->assertNull($line[1]->collaboratorId);
        $this->assertSame(self::CORTE, $line[0]->serviceId);
        $this->assertSame('2030-03-05', $line[0]->desiredDate);
        $this->assertEquals(self::at('2030-03-01 09:00'), $line[0]->joinedAt);
        $this->assertEqualsCanonicalizing([$late->id->value, $other->id->value], $line[1]->customerEntryIds, 'Ana has two active rows, one of another day.');
        $this->assertCount(2, $line[0]->customerEntryIds, 'Luis has two active rows, the one for Kevin included.');
    }

    /** A row that already has an offer, or that was left, is not listed: it is not waiting. */
    public function test_a_row_with_an_offer_is_not_listed(): void
    {
        $offered = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '09:00');
        $offered->offer(self::at('2030-03-05 09:00'));
        $this->entries->save($offered);

        $this->assertSame([], new ListWaitingForSpace($this->entries)->execute('2030-03-05', self::DANIELA));
    }

    /** Holding a row takes the lock of the line of the customer and tells that it still waits (LE-06). */
    public function test_holding_a_waiting_row_takes_the_lock_of_its_customer(): void
    {
        $entry = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '09:00');

        $waiting = new LockWaitingEntry($this->entries)->execute($entry->id->value, self::ANA);

        $this->assertTrue($waiting);
        $this->assertSame(1, $this->entries->locks());
    }

    /** A row that someone offered or the customer left in the meantime, one of another customer and one that does not exist are not waiting. */
    public function test_holding_a_row_that_does_not_wait_says_so(): void
    {
        $offered = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '09:00');
        $offered->offer(self::at('2030-03-05 09:00'));
        $left = $this->join(self::ANA, self::CORTE, null, '2030-03-05', '09:00');
        $left->leave(self::at('2030-03-05 09:00'));
        $waiting = $this->join(self::ANA, self::DEGRADADO, self::DANIELA, '2030-03-05', '09:00');
        $hold = new LockWaitingEntry($this->entries);

        $this->assertFalse($hold->execute($offered->id->value, self::ANA));
        $this->assertFalse($hold->execute($left->id->value, self::ANA));
        $this->assertFalse($hold->execute($waiting->id->value, self::LUIS), 'The row is not theirs.');
        $this->assertFalse($hold->execute('0192f0c4-3333-7000-8000-00000000ffff', self::ANA));
    }

    /** Marking a row as offered saves it with the place it had. */
    public function test_marking_a_row_as_offered_saves_it(): void
    {
        $entry = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '09:00');

        new MarkEntryOffered($this->entries)->execute($entry->id->value, self::at('2030-03-05 11:00'));

        $saved = $this->entries->findForUpdate($entry->id);
        $this->assertSame(WaitlistStatus::Offered, $saved?->status);
        $this->assertEquals(self::at('2030-03-01 09:00'), $saved->joinedAt);
    }

    /** A row that does not wait, or does not exist, cannot be marked as offered. */
    public function test_a_row_that_does_not_wait_cannot_be_marked_as_offered(): void
    {
        $entry = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '09:00');
        $mark = new MarkEntryOffered($this->entries);
        $mark->execute($entry->id->value, self::at('2030-03-05 11:00'));

        try {
            $mark->execute($entry->id->value, self::at('2030-03-05 11:01'));
            $this->fail('A row that had an offer received another.');
        } catch (EntryNotWaitingException) {
            $this->expectException(EntryNotFoundException::class);
            $mark->execute('0192f0c4-3333-7000-8000-00000000ffff', self::at('2030-03-05 11:01'));
        }
    }

    /** Putting a row back after its offer ended returns it to waiting, and a retry changes nothing (LE-05). */
    public function test_a_row_goes_back_to_the_line_once(): void
    {
        $entry = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '09:00');
        $entry->offer(self::at('2030-03-05 11:00'));
        $this->entries->save($entry);
        $back = new ReturnEntryToLine($this->entries);

        $back->execute($entry->id->value, self::at('2030-03-05 11:15'));
        $back->execute($entry->id->value, self::at('2030-03-05 11:16'));

        $saved = $this->entries->findForUpdate($entry->id);
        $this->assertSame(WaitlistStatus::Waiting, $saved?->status);
        $this->assertEquals(self::at('2030-03-05 11:15'), $saved->updatedAt);
    }

    /** A row that left while it had the offer stays left, and one that does not exist changes nothing. */
    public function test_a_row_that_left_does_not_come_back(): void
    {
        $entry = $this->join(self::ANA, self::DEGRADADO, null, '2030-03-05', '09:00');
        $entry->offer(self::at('2030-03-05 11:00'));
        $entry->leave(self::at('2030-03-05 11:05'));
        $this->entries->save($entry);
        $back = new ReturnEntryToLine($this->entries);

        $back->execute($entry->id->value, self::at('2030-03-05 11:15'));
        $back->execute('0192f0c4-3333-7000-8000-00000000ffff', self::at('2030-03-05 11:15'));

        $this->assertSame(WaitlistStatus::Left, $this->entries->findForUpdate($entry->id)?->status);
    }

    /** Put a customer in the line on the first of March at the time. */
    private function join(string $customer, string $service, ?string $collaborator, string $date, string $time): WaitlistEntry
    {
        $entry = WaitlistEntry::join(
            $this->entries->nextId(),
            new CustomerMembershipId($customer),
            new ServiceId($service),
            $collaborator === null ? null : new CollaboratorId($collaborator),
            DesiredDate::of($date),
            self::at("2030-03-01 {$time}"),
        );
        $this->entries->save($entry);

        return $entry;
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
