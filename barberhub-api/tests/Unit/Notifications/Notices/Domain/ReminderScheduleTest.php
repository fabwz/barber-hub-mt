<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Notices\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Src\Notifications\Notices\Domain\Services\ReminderSchedule;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;

/** Which reminders of a reservation still fit and when each is due (RF-33, NO-02), with fixed moments and without a framework. */
final class ReminderScheduleTest extends TestCase
{
    private static function cr(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('America/Costa_Rica'));
    }

    /** Made on Monday at 9:00 for Tuesday at 15:00, the reminders are Monday at 15:00 and Tuesday at 13:00. */
    public function test_a_reservation_made_two_days_ahead_has_both_reminders_at_their_hours(): void
    {
        $due = ReminderSchedule::from(self::cr('2030-03-05 15:00'), self::cr('2030-03-04 09:00'));

        $this->assertCount(2, $due);
        $this->assertSame(ReminderKind::DayBefore, $due[0]->kind);
        $this->assertEquals(self::cr('2030-03-04 15:00'), $due[0]->at);
        $this->assertSame(ReminderKind::TwoHoursBefore, $due[1]->kind);
        $this->assertEquals(self::cr('2030-03-05 13:00'), $due[1]->at);
    }

    /** With less than 24 h of notice only the 2 h reminder is left. */
    public function test_with_less_than_a_day_of_notice_only_the_two_hour_reminder_is_left(): void
    {
        $due = ReminderSchedule::from(self::cr('2030-03-05 15:00'), self::cr('2030-03-05 09:00'));

        $this->assertCount(1, $due);
        $this->assertSame(ReminderKind::TwoHoursBefore, $due[0]->kind);
        $this->assertEquals(self::cr('2030-03-05 13:00'), $due[0]->at);
    }

    /** With less than 2 h of notice there is none. */
    public function test_with_less_than_two_hours_of_notice_there_is_none(): void
    {
        $this->assertSame([], ReminderSchedule::from(self::cr('2030-03-05 15:00'), self::cr('2030-03-05 14:00')));
    }

    /** With exactly the lead time the reminder is still due, right now; one minute less and it is gone. */
    public function test_the_boundary_is_the_lead_time_itself(): void
    {
        $exact = ReminderSchedule::from(self::cr('2030-03-05 15:00'), self::cr('2030-03-04 15:00'));
        $late = ReminderSchedule::from(self::cr('2030-03-05 15:00'), self::cr('2030-03-04 15:01'));

        $this->assertCount(2, $exact);
        $this->assertCount(1, $late);
    }

    /** The hours are the same instants whatever the time zone the start is written in. */
    public function test_the_instants_do_not_depend_on_the_zone_of_the_start(): void
    {
        $utc = new DateTimeImmutable('2030-03-05 21:00:00', new DateTimeZone('UTC'));

        $due = ReminderSchedule::from($utc, self::cr('2030-03-04 09:00'));

        $this->assertEquals(self::cr('2030-03-05 13:00'), $due[1]->at);
    }
}
