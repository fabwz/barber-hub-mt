<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Invitations\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Catalog\Invitations\Domain\Entities\Invitation;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationId;
use Src\Catalog\Invitations\Domain\ValueObjects\InvitationToken;

final class InvitationTest extends TestCase
{
    private const string ID = '01980000-0000-7000-8000-000000000001';

    private const string SERVICE = '01980000-0000-7000-8000-0000000000a1';

    /** The link lasts 7 days from the invitation (UP-04). */
    public function test_the_invitation_expires_seven_days_after_it_is_made(): void
    {
        $now = new DateTimeImmutable('2030-03-01 08:00:00');

        $invitation = Invitation::create(new InvitationId(self::ID), 'lucia@correo.cr', [], $now);

        $this->assertSame(7, InvitationToken::TTL_DAYS);
        $this->assertEquals(new DateTimeImmutable('2030-03-08 08:00:00'), $invitation->expiresAt);
        $this->assertEquals($now, $invitation->createdAt);
    }

    /** The email is kept in lower case and without spaces, as the accounts keep theirs, and a repeated service counts once. */
    public function test_the_email_is_normalised_and_the_services_are_not_repeated(): void
    {
        $invitation = Invitation::create(new InvitationId(self::ID), ' Lucia@Correo.CR ', [self::SERVICE, self::SERVICE], new DateTimeImmutable('2030-03-01 08:00:00'));

        $this->assertSame('lucia@correo.cr', $invitation->email);
        $this->assertSame([self::SERVICE], $invitation->serviceIds);
    }

    /** The token is 256 random bits in hexadecimal, and two tokens differ. */
    public function test_a_token_is_long_random_and_different_every_time(): void
    {
        $first = InvitationToken::generate();
        $second = InvitationToken::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->plain);
        $this->assertNotSame($first->plain, $second->plain);
    }

    /** What is stored is the SHA-256 of the token, never the token (UP-04). */
    public function test_the_stored_form_is_the_sha256_hash_not_the_token(): void
    {
        $token = InvitationToken::generate();

        $this->assertSame(hash('sha256', $token->plain), $token->hash());
        $this->assertNotSame($token->plain, $token->hash());
        $this->assertSame($token->hash(), InvitationToken::fromPlain($token->plain)->hash());
    }
}
