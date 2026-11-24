<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Notices\Application;

use PHPUnit\Framework\TestCase;
use Src\Notifications\Notices\Application\DTOs\NoticeCommand;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Tests\Support\Notifications\BuildsNoticeScenario;

/** The notices of a confirmation, an offer, a cancellation and a reschedule reach the customer through the channel, and nobody without an email (RF-34, CL-06). */
final class SendNoticeTest extends TestCase
{
    use BuildsNoticeScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildNoticeScenario();
    }

    private function command(NoticeType $type, string $membership = self::ANA): NoticeCommand
    {
        return new NoticeCommand($type, $membership, 'service-1', 'collaborator-1', self::at('05', '15:00'), self::at('05', '15:45'), self::at('01', '10:15'), self::at('04', '11:00'), idempotencyKey: 'key-1');
    }

    /** Each kind of notice goes to the person with the barbershop, the names and the key. */
    public function test_each_kind_reaches_the_customer(): void
    {
        foreach ([NoticeType::Confirmation, NoticeType::Offer, NoticeType::Cancellation, NoticeType::Reschedule] as $type) {
            $this->assertTrue($this->sendNotice->execute($this->command($type)));
        }

        $this->assertCount(4, $this->channel->sent);
        $this->assertSame([NoticeType::Confirmation, NoticeType::Offer, NoticeType::Cancellation, NoticeType::Reschedule], array_map(fn ($notice): NoticeType => $notice->type, $this->channel->sent));
        $this->assertSame('ana@barberhub.test', $this->channel->sent[0]->recipient->email);
        $this->assertSame('key-1', $this->channel->sent[0]->idempotencyKey);
        $this->assertSame('http://localhost/api/v1/archivos/barbershops/b/logo/l.png', $this->channel->sent[0]->branding->logoUrl);
    }

    /** A customer created by the barbershop with no email receives nothing (CL-06). */
    public function test_a_customer_without_email_receives_nothing(): void
    {
        $this->assertFalse($this->sendNotice->execute($this->command(NoticeType::Confirmation, self::WALK_IN)));
        $this->assertCount(0, $this->channel->sent);
    }

    /** The preference of the reminders does not touch the other notices: they cannot be turned off (NO-10). */
    public function test_turning_the_reminders_off_does_not_stop_the_other_notices(): void
    {
        $this->preferences->choices[self::ANA] = false;

        $this->assertTrue($this->sendNotice->execute($this->command(NoticeType::Confirmation)));
        $this->assertTrue($this->sendNotice->execute($this->command(NoticeType::Offer)));
    }
}
