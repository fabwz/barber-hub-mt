<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Notices\Application;

use PHPUnit\Framework\TestCase;
use Src\Notifications\Notices\Application\DTOs\NoticeCommand;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Domain\ValueObjects\ReminderKind;
use Tests\Support\Notifications\BuildsNoticeScenario;

/** What the assisted client of an owner hears (RF-12, CL-06, NO-10): with an email, the notices and the reminders as any customer, on by default; without it, nothing. */
final class AssistedClientNoticesTest extends TestCase
{
    use BuildsNoticeScenario;

    private const string ASSISTED_WITH_EMAIL = 'assisted-rosa';

    private const string ASSISTED_WITHOUT_EMAIL = 'assisted-walk-in';

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNoticeScenario();
    }

    private function command(NoticeType $type, string $assistedClient): NoticeCommand
    {
        return new NoticeCommand($type, null, 'service-1', 'collaborator-1', self::at('05', '15:00'), self::at('05', '15:45'), idempotencyKey: 'key-1', assistedClientId: $assistedClient);
    }

    /** The notice goes to the email of the assisted client, with their name. */
    public function test_a_notice_reaches_the_assisted_client_with_email(): void
    {
        $this->assertTrue($this->sendNotice->execute($this->command(NoticeType::Confirmation, self::ASSISTED_WITH_EMAIL)));

        $this->assertSame(['Rosa Campos', 'rosa@barberhub.test'], [$this->channel->sent[0]->recipient->name, $this->channel->sent[0]->recipient->email]);
    }

    /** Without an email nothing is sent and nothing fails (CL-06). */
    public function test_a_notice_without_email_sends_nothing(): void
    {
        $this->assertFalse($this->sendNotice->execute($this->command(NoticeType::Cancellation, self::ASSISTED_WITHOUT_EMAIL)));

        $this->assertSame([], $this->channel->sent);
    }

    /** An assisted client with an email has both reminders scheduled, with no preference to turn them off. */
    public function test_reminders_are_scheduled_by_default_for_an_assisted_client_with_email(): void
    {
        $this->scheduleReminders->execute(self::RESERVATION, null, self::at('05', '15:00'), self::at('04', '09:00'), self::ASSISTED_WITH_EMAIL);

        $this->assertNotNull($this->reminders->dueAt(self::RESERVATION, ReminderKind::DayBefore));
        $this->assertNotNull($this->reminders->dueAt(self::RESERVATION, ReminderKind::TwoHoursBefore));
        $this->assertCount(2, $this->jobs->left);
    }

    /** The notice of a customer role without email is still not sent, whoever else has one: the assisted client is not mixed up with the role. */
    public function test_a_role_without_email_still_receives_nothing(): void
    {
        $this->assertFalse($this->sendNotice->execute(new NoticeCommand(NoticeType::Cancellation, self::WALK_IN, 'service-1', 'collaborator-1', self::at('05', '15:00'), self::at('05', '15:45'))));

        $this->assertSame([], $this->channel->sent);
    }

    /** An assisted client without an email has no reminder: none is recorded and no task is left (CL-06, NO-02). */
    public function test_no_reminder_is_scheduled_without_email(): void
    {
        $this->scheduleReminders->execute(self::RESERVATION, null, self::at('05', '15:00'), self::at('04', '09:00'), self::ASSISTED_WITHOUT_EMAIL);

        $this->assertSame(0, $this->reminders->count());
        $this->assertSame([], $this->jobs->left);
    }

    /** When its time comes the reminder is sent to the email of the assisted client, once. */
    public function test_the_reminder_is_sent_to_the_assisted_client_once(): void
    {
        $this->reservation(self::at('05', '15:00'), membership: null, assistedClient: self::ASSISTED_WITH_EMAIL);
        $this->scheduleReminders->execute(self::RESERVATION, null, self::at('05', '15:00'), self::at('04', '09:00'), self::ASSISTED_WITH_EMAIL);

        $first = $this->sendReminder->execute(self::RESERVATION, ReminderKind::DayBefore, self::at('04', '15:00'), self::at('04', '15:00'));
        $second = $this->sendReminder->execute(self::RESERVATION, ReminderKind::DayBefore, self::at('04', '15:00'), self::at('04', '15:00'));

        $this->assertSame([true, false], [$first, $second]);
        $this->assertCount(1, $this->channel->sent);
        $this->assertSame('rosa@barberhub.test', $this->channel->sent[0]->recipient->email);
    }

    /** A reminder of an assisted client without email is never sent. */
    public function test_the_reminder_of_a_client_without_email_is_not_sent(): void
    {
        $this->reservation(self::at('05', '15:00'), membership: null, assistedClient: self::ASSISTED_WITHOUT_EMAIL);

        $sent = $this->sendReminder->execute(self::RESERVATION, ReminderKind::DayBefore, self::at('04', '15:00'), self::at('04', '15:00'));

        $this->assertFalse($sent);
        $this->assertSame([], $this->channel->sent);
    }
}
