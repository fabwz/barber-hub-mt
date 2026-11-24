<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Notices\Application;

use PHPUnit\Framework\TestCase;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Tests\Support\Notifications\BuildsNoticeScenario;

/** Leaving the reminders of a reservation for their time, moving them when it is rescheduled and leaving none to someone who cannot or does not want them (RF-33). */
final class ScheduleRemindersTest extends TestCase
{
    use BuildsNoticeScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNoticeScenario();
    }

    /** Both reminders are recorded and one task is left for each, at its hour. */
    public function test_it_leaves_one_task_per_reminder_at_its_hour(): void
    {
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('04', '09:00'));

        $this->assertEquals(self::at('04', '15:00'), $this->reminders->dueAt(self::RESERVATION, ReminderKind::DayBefore));
        $this->assertEquals(self::at('05', '13:00'), $this->reminders->dueAt(self::RESERVATION, ReminderKind::TwoHoursBefore));
        $this->assertCount(2, $this->jobs->left);
        $this->assertEquals(self::at('04', '15:00'), $this->jobs->left[0][2]);
        $this->assertEquals(self::at('05', '13:00'), $this->jobs->left[1][2]);
    }

    /** A reservation made with less than 24 h of notice only gets the 2 h reminder; with less than 2 h, none (NO-02). */
    public function test_reminders_that_no_longer_fit_are_not_left(): void
    {
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('05', '09:00'));
        $this->scheduleReminders->execute('reservation-2', self::ANA, self::at('05', '15:00'), self::at('05', '14:30'));

        $this->assertNull($this->reminders->dueAt(self::RESERVATION, ReminderKind::DayBefore));
        $this->assertNotNull($this->reminders->dueAt(self::RESERVATION, ReminderKind::TwoHoursBefore));
        $this->assertNull($this->reminders->dueAt('reservation-2', ReminderKind::TwoHoursBefore));
        $this->assertCount(1, $this->jobs->left);
    }

    /** The same event twice changes nothing: no second row and no second task (NO-03). */
    public function test_repeating_the_event_leaves_nothing_new(): void
    {
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('04', '09:00'));
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('04', '09:01'));

        $this->assertSame(2, $this->reminders->count());
        $this->assertCount(2, $this->jobs->left);
    }

    /** A customer who turned the reminders off, or who has no email, gets no reminder at all (NO-10, CL-06). */
    public function test_nobody_who_cannot_or_will_not_receive_them_gets_any(): void
    {
        $this->preferences->choices[self::ANA] = false;
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('04', '09:00'));
        $this->scheduleReminders->execute('reservation-2', self::WALK_IN, self::at('05', '15:00'), self::at('04', '09:00'));

        $this->assertSame(0, $this->reminders->count());
        $this->assertCount(0, $this->jobs->left);
    }

    /** Rescheduling after the 24 h reminder went out moves both to the new hours and makes them unsent; the old tasks find another instant (NO-02, NO-03). */
    public function test_a_reschedule_moves_the_reminders_even_if_the_day_one_was_already_sent(): void
    {
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('01', '09:00'));
        $this->reservation(self::at('05', '15:00'));
        $this->assertTrue($this->sendReminder->execute(self::RESERVATION, ReminderKind::DayBefore, self::at('04', '15:00'), self::at('04', '15:00')));
        $this->assertTrue($this->reminders->wasSent(self::RESERVATION, ReminderKind::DayBefore));

        $this->reservation(self::at('07', '10:00'));
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('07', '10:00'), self::at('04', '16:00'));

        $this->assertEquals(self::at('06', '10:00'), $this->reminders->dueAt(self::RESERVATION, ReminderKind::DayBefore));
        $this->assertFalse($this->reminders->wasSent(self::RESERVATION, ReminderKind::DayBefore), 'The new hour has its own reminder.');
        $this->assertEquals(self::at('07', '08:00'), $this->reminders->dueAt(self::RESERVATION, ReminderKind::TwoHoursBefore));
        $this->assertFalse($this->sendReminder->execute(self::RESERVATION, ReminderKind::TwoHoursBefore, self::at('05', '13:00'), self::at('05', '13:00')), 'The task of the old hour does nothing.');
        $this->assertTrue($this->sendReminder->execute(self::RESERVATION, ReminderKind::DayBefore, self::at('06', '10:00'), self::at('06', '10:00')));
        $this->assertCount(2, $this->channel->sent, 'One for the old hour and one for the new one; none twice.');
    }

    /** A reschedule to a time too close for a reminder forgets it, and the old task finds no row. */
    public function test_a_reschedule_to_a_closer_hour_forgets_the_reminder_that_no_longer_fits(): void
    {
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('08', '15:00'), self::at('04', '09:00'));

        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('05', '09:00'));

        $this->assertNull($this->reminders->dueAt(self::RESERVATION, ReminderKind::DayBefore));
        $this->assertEquals(self::at('05', '13:00'), $this->reminders->dueAt(self::RESERVATION, ReminderKind::TwoHoursBefore));
    }
}
