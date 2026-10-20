<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\ValueObjects\Phone;
use Src\Shared\Domain\ValueObjects\UserId;

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

    /** An account created by the owner has no phone nor acceptances and must change its temporary password (UP-04). */
    public function test_staff_account_must_change_its_password(): void
    {
        $user = User::createStaff(new UserId(self::USER_ID), new UserName('Kevin Solano'), new Email('kevin@barberhub.test'), 'hash');

        $this->assertTrue($user->active);
        $this->assertTrue($user->mustChangePassword);
        $this->assertNull($user->phone);
        $this->assertNull($user->termsAcceptedAt);
    }

    /** A customer registration does not ask for a password change. */
    public function test_customer_account_does_not_have_to_change_its_password(): void
    {
        $now = new DateTimeImmutable('2026-10-19 10:00:00');
        $user = User::create(new UserId(self::USER_ID), new UserName('Ana Mora'), new Email('ana@barberhub.test'), new Phone('8888-0000'), 'hash', $now, $now);

        $this->assertFalse($user->mustChangePassword);
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
            false,
        );

        $this->assertSame('stored-hash', $user->passwordHash);
        $this->assertFalse($user->active);
        $this->assertNull($user->phone);
        $this->assertNull($user->termsAcceptedAt);
        $this->assertNull($user->privacyAcceptedAt);
    }
}
