<?php

declare(strict_types=1);

namespace Tests\Unit\Waitlist\Entries\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Waitlist\Entries\Domain\Entities\WaitlistEntry;
use Src\Waitlist\Entries\Domain\Exceptions\DesiredDateInPastException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotLeavableException;
use Src\Waitlist\Entries\Domain\Exceptions\EntryNotWaitingException;
use Src\Waitlist\Entries\Domain\ValueObjects\CollaboratorId;
use Src\Waitlist\Entries\Domain\ValueObjects\CustomerMembershipId;
use Src\Waitlist\Entries\Domain\ValueObjects\DesiredDate;
use Src\Waitlist\Entries\Domain\ValueObjects\ServiceId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistEntryId;
use Src\Waitlist\Entries\Domain\ValueObjects\WaitlistStatus;

/** The rules of a row of the waitlist, without a database: LE-01, LE-02 and RF-30. */
final class WaitlistEntryTest extends TestCase
{
    private const string ENTRY = '0192f3a0-0000-7000-8000-000000000001';

    private const string CUSTOMER = '0192f3a0-0000-7000-8000-000000000002';

    private const string SERVICE = '0192f3a0-0000-7000-8000-000000000003';

    private const string COLLABORATOR = '0192f3a0-0000-7000-8000-000000000004';

    /** A new row waits, keeps what the customer asked for and takes the instant it joined as its place in the line (LE-01, LE-02). */
    public function test_a_new_row_waits_and_keeps_what_was_asked_for(): void
    {
        $joined = self::at('2030-03-01 08:00:00');

        $entry = self::join($joined, self::COLLABORATOR);

        $this->assertSame(WaitlistStatus::Waiting, $entry->status);
        $this->assertTrue($entry->isActive());
        $this->assertSame(self::SERVICE, $entry->serviceId->value);
        $this->assertSame(self::COLLABORATOR, $entry->collaboratorId?->value);
        $this->assertSame('2030-03-05', $entry->desiredDate->value);
        $this->assertSame($joined, $entry->joinedAt);
    }

    /** A row without a collaborator means "any" (LE-01). */
    public function test_a_row_may_ask_for_any_collaborator(): void
    {
        $this->assertNull(self::join(self::at('2030-03-01 08:00:00'), null)->collaboratorId);
    }

    /** The customer leaves while it waits or has an offer (RF-30). */
    public function test_the_customer_leaves_a_row_that_is_active(): void
    {
        foreach ([WaitlistStatus::Waiting, WaitlistStatus::Offered] as $status) {
            $entry = self::stored($status);
            $left = self::at('2030-03-02 09:00:00');

            $entry->leave($left);

            $this->assertSame(WaitlistStatus::Left, $entry->status);
            $this->assertFalse($entry->isActive());
            $this->assertSame($left, $entry->updatedAt);
        }
    }

    /** Leaving twice changes nothing: not even the instant of the first time. */
    public function test_leaving_twice_changes_nothing(): void
    {
        $entry = self::stored(WaitlistStatus::Left);
        $before = $entry->updatedAt;

        $entry->leave(self::at('2030-03-03 09:00:00'));

        $this->assertSame(WaitlistStatus::Left, $entry->status);
        $this->assertSame($before, $entry->updatedAt);
    }

    /** A row that ended with a reservation cannot be left. */
    public function test_a_fulfilled_row_cannot_be_left(): void
    {
        $entry = self::stored(WaitlistStatus::Fulfilled);

        $this->expectException(EntryNotLeavableException::class);

        $entry->leave(self::at('2030-03-03 09:00:00'));
    }

    /** An offer moves a waiting row to offered without touching the place it has in the line (RF-31, LE-02). */
    public function test_an_offer_moves_a_waiting_row_and_keeps_its_place(): void
    {
        $entry = self::stored(WaitlistStatus::Waiting);
        $joined = $entry->joinedAt;
        $offered = self::at('2030-03-05 11:00:00');

        $entry->offer($offered);

        $this->assertSame(WaitlistStatus::Offered, $entry->status);
        $this->assertTrue($entry->isActive());
        $this->assertSame($joined, $entry->joinedAt);
        $this->assertSame($offered, $entry->updatedAt);
    }

    /** Only a row that waits can be offered a space: not one that already has an offer, left, or ended with a reservation. */
    public function test_only_a_waiting_row_can_be_offered(): void
    {
        foreach ([WaitlistStatus::Offered, WaitlistStatus::Left, WaitlistStatus::Fulfilled] as $status) {
            $entry = self::stored($status);

            try {
                $entry->offer(self::at('2030-03-05 11:00:00'));
                $this->fail("A row that was {$status->value} received an offer.");
            } catch (EntryNotWaitingException) {
                $this->assertSame($status, $entry->status);
            }
        }
    }

    /** When the offer ends with no reservation the row goes back to waiting with the place it had (LE-05). */
    public function test_an_offered_row_goes_back_to_the_line_with_its_place(): void
    {
        $entry = self::stored(WaitlistStatus::Offered);
        $joined = $entry->joinedAt;
        $back = self::at('2030-03-05 11:15:00');

        $entry->returnToLine($back);

        $this->assertSame(WaitlistStatus::Waiting, $entry->status);
        $this->assertSame($joined, $entry->joinedAt);
        $this->assertSame($back, $entry->updatedAt);
    }

    /** Going back changes nothing for a row that is not offered: one that left meanwhile does not come back, and a repeat is harmless. */
    public function test_going_back_changes_nothing_unless_the_row_is_offered(): void
    {
        foreach ([WaitlistStatus::Waiting, WaitlistStatus::Left, WaitlistStatus::Fulfilled] as $status) {
            $entry = self::stored($status);
            $before = $entry->updatedAt;

            $entry->returnToLine(self::at('2030-03-05 11:15:00'));

            $this->assertSame($status, $entry->status);
            $this->assertSame($before, $entry->updatedAt);
        }
    }

    /** Only waiting and offered rows are active, so only they count against the limit of 3 (LE-01). */
    public function test_only_waiting_and_offered_are_active(): void
    {
        $this->assertSame(3, WaitlistEntry::MAX_ACTIVE_PER_CUSTOMER);
        $this->assertTrue(WaitlistStatus::Waiting->isActive());
        $this->assertTrue(WaitlistStatus::Offered->isActive());
        $this->assertFalse(WaitlistStatus::Fulfilled->isActive());
        $this->assertFalse(WaitlistStatus::Left->isActive());
    }

    /** Today and any later day are accepted, with today read in Costa Rica whatever the zone of the instant (LE-01). */
    public function test_the_day_may_be_today_or_later_in_costa_rica(): void
    {
        $now = self::at('2030-03-01 08:00:00');

        $this->assertSame('2030-03-01', DesiredDate::upcoming('2030-03-01', $now)->value);
        $this->assertSame('2030-12-31', DesiredDate::upcoming('2030-12-31', $now)->value);

        // 02:00 UTC of the 2nd is still 20:00 of the 1st in Costa Rica.
        $utc = new DateTimeImmutable('2030-03-02 02:00:00', new DateTimeZone('UTC'));
        $this->assertSame('2030-03-01', DesiredDate::upcoming('2030-03-01', $utc)->value);
    }

    /** A day that has passed cannot get an offer, so it is refused on the field `fecha`. */
    public function test_a_past_day_is_refused(): void
    {
        $exception = null;

        try {
            DesiredDate::upcoming('2030-02-28', self::at('2030-03-01 08:00:00'));
        } catch (DesiredDateInPastException $exception) {
        }

        $this->assertInstanceOf(DesiredDateInPastException::class, $exception);
        $this->assertSame('fecha', $exception->field());
    }

    /** Only a real calendar date is a day. */
    public function test_a_day_must_be_a_real_date(): void
    {
        foreach (['2030-02-30', '05/03/2030', '2030-3-5', '', 'mañana'] as $value) {
            try {
                DesiredDate::of($value);
                $this->fail("{$value} was accepted");
            } catch (InvalidValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private static function join(DateTimeImmutable $at, ?string $collaborator): WaitlistEntry
    {
        return WaitlistEntry::join(
            new WaitlistEntryId(self::ENTRY),
            new CustomerMembershipId(self::CUSTOMER),
            new ServiceId(self::SERVICE),
            $collaborator === null ? null : new CollaboratorId($collaborator),
            DesiredDate::of('2030-03-05'),
            $at,
        );
    }

    private static function stored(WaitlistStatus $status): WaitlistEntry
    {
        $at = self::at('2030-03-01 08:00:00');

        return WaitlistEntry::reconstitute(
            new WaitlistEntryId(self::ENTRY),
            new CustomerMembershipId(self::CUSTOMER),
            new ServiceId(self::SERVICE),
            null,
            DesiredDate::of('2030-03-05'),
            $status,
            $at,
            $at,
            $at,
        );
    }

    private static function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }
}
