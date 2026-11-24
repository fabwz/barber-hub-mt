<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Notices\Application;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Tests\Support\Notifications\BuildsNoticeScenario;

/** Sending a reminder when its time comes: once, only for a confirmed reservation that is still where the reminder expects, only to who wants it (RF-33, NO-03). */
final class SendReminderTest extends TestCase
{
    use BuildsNoticeScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNoticeScenario();
        $this->reservation(self::at('05', '15:00'));
        $this->scheduleReminders->execute(self::RESERVATION, self::ANA, self::at('05', '15:00'), self::at('04', '09:00'));
    }

    private function sendDayBefore(): bool
    {
        return $this->sendReminder->execute(self::RESERVATION, ReminderKind::DayBefore, self::at('04', '15:00'), self::at('04', '15:00'));
    }

    /** The reminder reaches the customer with the facts of the reservation and a key that is the same for the same reminder. */
    public function test_it_sends_the_reminder_with_the_data_of_the_reservation(): void
    {
        $this->assertTrue($this->sendDayBefore());

        $notice = $this->channel->sent[0];
        $this->assertSame(NoticeType::Reminder, $notice->type);
        $this->assertSame('ana@barberhub.test', $notice->recipient->email);
        $this->assertSame('Pura Navaja', $notice->branding->barbershopName);
        $this->assertSame('Degradado', $notice->serviceName);
        $this->assertSame('Daniela', $notice->collaboratorName);
        $this->assertEquals(self::at('05', '15:00'), $notice->startsAt);
        $this->assertSame(ReminderKind::DayBefore, $notice->reminder);
        $this->assertSame('reminder-reservation-1-24h-'.self::at('04', '15:00')->getTimestamp(), $notice->idempotencyKey);
    }

    /** Running the same task again, as the restart of a worker can, sends nothing more (NO-03). */
    public function test_a_reminder_goes_out_only_once(): void
    {
        $this->assertTrue($this->sendDayBefore());
        $this->assertFalse($this->sendDayBefore());

        $this->assertCount(1, $this->channel->sent);
    }

    /** A cancelled, expired or absent reservation (anything but confirmed) sends no reminder (NO-03). */
    public function test_a_reservation_that_is_no_longer_confirmed_sends_nothing(): void
    {
        $this->reservation(self::at('05', '15:00'), confirmed: false);

        $this->assertFalse($this->sendDayBefore());
        $this->assertCount(0, $this->channel->sent);
    }

    /** A reservation that disappeared or whose start moved without the reminder being moved sends nothing. */
    public function test_a_reservation_that_is_gone_or_moved_sends_nothing(): void
    {
        unset($this->reservations->items[self::RESERVATION]);
        $this->assertFalse($this->sendDayBefore());

        $this->reservation(self::at('06', '15:00'));
        $this->assertFalse($this->sendDayBefore());

        $this->assertCount(0, $this->channel->sent);
    }

    /** A customer who turned the reminders off after the reservation does not receive the ones that were already left (NO-10). */
    public function test_a_customer_who_turned_them_off_receives_nothing(): void
    {
        $this->preferences->choices[self::ANA] = false;

        $this->assertFalse($this->sendDayBefore());
        $this->assertCount(0, $this->channel->sent);
    }

    /** A customer without an email receives nothing and nothing is marked as sent (CL-06). */
    public function test_a_customer_without_email_receives_nothing(): void
    {
        $this->reservation(self::at('05', '15:00'), membership: self::WALK_IN);
        $this->reminders->schedule(self::RESERVATION, ReminderKind::DayBefore, self::at('04', '15:00'));

        $this->assertFalse($this->sendDayBefore());
        $this->assertCount(0, $this->channel->sent);
    }

    /** A failure of the channel leaves the exception to the queue, which retries; with a transaction the claim is undone, here the retry is the next call. */
    public function test_a_failure_of_the_channel_reaches_the_queue(): void
    {
        $this->channel->failing = true;

        $this->expectException(RuntimeException::class);

        $this->sendDayBefore();
    }
}
