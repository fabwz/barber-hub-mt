<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Notices\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Notifications\Notices\Application\Contracts\PasswordResets;
use Src\Notifications\Notices\Application\Contracts\WebLinks;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Application\DTOs\PasswordResetLink;
use Src\Notifications\Notices\Application\DTOs\Recipient;
use Src\Notifications\Notices\Application\UseCases\SendPasswordResetNotice;
use Tests\Support\Notifications\RecordingNoticeChannel;
use Tests\Support\Shared\MutableClock;

final class SendPasswordResetNoticeTest extends TestCase
{
    private RecordingNoticeChannel $channel;

    private ?PasswordResetLink $link;

    /** The notice is sent with a channel that records it, and the accounts answer the link the test sets. */
    protected function setUp(): void
    {
        $this->channel = new RecordingNoticeChannel;
        $this->link = new PasswordResetLink(new Recipient('Ana Mora', 'ana@barberhub.test'), 'tok-123', new DateTimeImmutable('2030-03-01 09:00:00'));
    }

    /** The notice is of the platform, goes to the person and points at the screen of the web client (RF-41). */
    public function test_sends_the_link_to_the_person(): void
    {
        $this->assertTrue($this->send());

        $this->assertCount(1, $this->channel->sent);
        $notice = $this->channel->sent[0];
        $this->assertSame(NoticeType::PasswordReset, $notice->type);
        $this->assertSame('ana@barberhub.test', $notice->recipient->email);
        $this->assertSame('BarberHub', $notice->branding->barbershopName);
        $this->assertSame('https://web.test/restablecer?t=tok-123', $notice->actionUrl);
        $this->assertEquals(new DateTimeImmutable('2030-03-01 08:00:00'), $notice->startsAt);
        $this->assertEquals(new DateTimeImmutable('2030-03-01 09:00:00'), $notice->expiresAt);
    }

    /** With no active account, nothing is sent and the caller learns nothing more. */
    public function test_sends_nothing_without_an_account(): void
    {
        $this->link = null;

        $this->assertFalse($this->send());
        $this->assertSame([], $this->channel->sent);
    }

    /** A failure of the channel is thrown, so the task of the queue retries it. */
    public function test_a_failing_channel_throws(): void
    {
        $this->channel->failing = true;

        $this->expectException(\RuntimeException::class);

        $this->send();
    }

    private function send(): bool
    {
        $resets = new class($this->link) implements PasswordResets
        {
            public function __construct(private readonly ?PasswordResetLink $link) {}

            public function issueFor(string $email): ?PasswordResetLink
            {
                return $this->link;
            }
        };
        $links = new class implements WebLinks
        {
            public function passwordReset(string $token): string
            {
                return 'https://web.test/restablecer?t='.$token;
            }
        };

        return new SendPasswordResetNotice($resets, $links, $this->channel, new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00')))->execute('ana@barberhub.test');
    }
}
