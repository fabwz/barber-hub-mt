<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\Phone;
use Src\Identity\Users\Domain\ValueObjects\UserId;
use Src\Identity\Users\Domain\ValueObjects\UserName;

final class UserTest extends TestCase
{
    private const string USER_ID = '0192f0c4-7b1e-7c3a-9f1d-2b6a4e8c0d44';

    /** A new account starts active and keeps the date of each acceptance. */
    public function test_new_account_is_active_and_keeps_both_acceptances(): void
    {
        $terms = new DateTimeImmutable('2026-10-19 10:00:00');
        $privacy = new DateTimeImmutable('2026-10-19 10:00:05');

        $user = User::create(
            new UserId(self::USER_ID),
            new UserName('Ana Mora'),
            new Email('ana@barberhub.test'),
            new Phone('8888-0000'),
            'hash',
            $terms,
            $privacy,
        );

        $this->assertTrue($user->active);
        $this->assertSame($terms, $user->termsAcceptedAt);
        $this->assertSame($privacy, $user->privacyAcceptedAt);
        $this->assertSame('hash', $user->passwordHash);
    }

    /** A stored account keeps its state, even when it was deactivated or has no acceptances. */
    public function test_reconstitute_keeps_the_stored_state(): void
    {
        $user = User::reconstitute(
            new UserId(self::USER_ID),
            new UserName('Ana Mora'),
            new Email('ana@barberhub.test'),
            null,
            'stored-hash',
            false,
            null,
            null,
        );

        $this->assertSame('stored-hash', $user->passwordHash);
        $this->assertFalse($user->active);
        $this->assertNull($user->phone);
        $this->assertNull($user->termsAcceptedAt);
        $this->assertNull($user->privacyAcceptedAt);
    }
}
