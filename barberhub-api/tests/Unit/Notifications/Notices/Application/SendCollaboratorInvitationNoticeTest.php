<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications\Notices\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Src\Notifications\Notices\Application\Contracts\BarbershopBranding;
use Src\Notifications\Notices\Application\Contracts\CollaboratorInvitations;
use Src\Notifications\Notices\Application\Contracts\WebLinks;
use Src\Notifications\Notices\Application\DTOs\Branding;
use Src\Notifications\Notices\Application\DTOs\CollaboratorInvitationLink;
use Src\Notifications\Notices\Application\DTOs\NoticeType;
use Src\Notifications\Notices\Application\DTOs\Recipient;
use Src\Notifications\Notices\Application\UseCases\SendCollaboratorInvitationNotice;
use Tests\Support\Notifications\RecordingNoticeChannel;
use Tests\Support\Shared\MutableClock;

final class SendCollaboratorInvitationNoticeTest extends TestCase
{
    private RecordingNoticeChannel $channel;

    private ?CollaboratorInvitationLink $link;

    /** The notice is sent with a channel that records it, and the invitations answer the link the test sets. */
    protected function setUp(): void
    {
        $this->channel = new RecordingNoticeChannel;
        $this->link = new CollaboratorInvitationLink(new Recipient('', 'lucia@correo.cr'), 'tok-123', new DateTimeImmutable('2030-03-08 08:00:00'));
    }

    /** The notice is in the name of the barbershop, goes to the invited email and points at the screen of the web client (RF-06, MT-08). */
    public function test_sends_the_link_in_the_name_of_the_barbershop(): void
    {
        $this->assertTrue($this->send());

        $this->assertCount(1, $this->channel->sent);
        $notice = $this->channel->sent[0];
        $this->assertSame(NoticeType::CollaboratorInvitation, $notice->type);
        $this->assertSame('lucia@correo.cr', $notice->recipient->email);
        $this->assertSame('Pura Navaja', $notice->branding->barbershopName);
        $this->assertSame('https://api.test/logo.png', $notice->branding->logoUrl);
        $this->assertSame('https://web.test/invitacion?t=tok-123', $notice->actionUrl);
        $this->assertEquals(new DateTimeImmutable('2030-03-01 08:00:00'), $notice->startsAt);
        $this->assertEquals(new DateTimeImmutable('2030-03-08 08:00:00'), $notice->expiresAt);
    }

    /** An invitation that was cancelled, used or has expired sends nothing. */
    public function test_sends_nothing_when_the_invitation_is_no_longer_pending(): void
    {
        $this->link = null;

        $this->assertFalse($this->send());
        $this->assertSame([], $this->channel->sent);
    }

    /** A failure of the channel is thrown, so the task of the queue retries it. */
    public function test_a_failing_channel_throws(): void
    {
        $this->channel->failing = true;

        $this->expectException(RuntimeException::class);

        $this->send();
    }

    private function send(): bool
    {
        $invitations = new class($this->link) implements CollaboratorInvitations
        {
            public function __construct(private readonly ?CollaboratorInvitationLink $link) {}

            public function issueLink(string $invitationId): ?CollaboratorInvitationLink
            {
                return $this->link;
            }
        };
        $branding = new class implements BarbershopBranding
        {
            public function current(): Branding
            {
                return new Branding('Pura Navaja', 'https://api.test/logo.png');
            }
        };
        $links = new class implements WebLinks
        {
            public function passwordReset(string $token): string
            {
                return 'https://web.test/restablecer?t='.$token;
            }

            public function invitationAcceptance(string $token): string
            {
                return 'https://web.test/invitacion?t='.$token;
            }
        };

        return new SendCollaboratorInvitationNotice($invitations, $branding, $links, $this->channel, new MutableClock(new DateTimeImmutable('2030-03-01 08:00:00')))->execute('any');
    }
}
