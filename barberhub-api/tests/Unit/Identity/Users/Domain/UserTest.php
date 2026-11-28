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

    /** Changing the password swaps the hash, ends the temporary state and keeps everything else (UP-04, RF-40). */
    public function test_changing_the_password_replaces_the_hash_and_clears_the_temporary_flag(): void
    {
        $staff = User::createStaff(new UserId(self::USER_ID), new UserName('Kevin Solano'), new Email('kevin@barberhub.test'), 'old-hash');

        $changed = $staff->changePassword('new-hash');

        $this->assertSame('new-hash', $changed->passwordHash);
        $this->assertFalse($changed->mustChangePassword);
        $this->assertTrue($changed->id->equals($staff->id));
        $this->assertSame($staff->email, $changed->email);
        $this->assertTrue($changed->active);
        $this->assertSame('old-hash', $staff->passwordHash);
        $this->assertTrue($staff->mustChangePassword);
    }

    /** Correcting the profile swaps name, email and phone and keeps the hash, the acceptances and the temporary state (RF-45). */
    public function test_updating_the_profile_keeps_the_rest_of_the_account(): void
    {
        $staff = User::createStaff(new UserId(self::USER_ID), new UserName('Kevin Solano'), new Email('kevin@barberhub.test'), 'hash');

        $updated = $staff->updateProfile(new UserName('Kevin S.'), new Email('ks@barberhub.test'), new Phone('8888-0000'));

        $this->assertSame('Kevin S.', $updated->name->value);
        $this->assertSame('ks@barberhub.test', $updated->email->value);
        $this->assertSame('8888-0000', $updated->phone?->value);
        $this->assertSame('hash', $updated->passwordHash);
        $this->assertTrue($updated->mustChangePassword);
        $this->assertTrue($updated->id->equals($staff->id));
        $this->assertSame('Kevin Solano', $staff->name->value);
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

    /** Only an operator is an operator: every other way to create an account leaves the mark off, and changing the account keeps it (PL-01). */
    public function test_only_the_operator_factory_marks_the_account(): void
    {
        $id = new UserId(self::USER_ID);
        $name = new UserName('Ana Mora');
        $email = new Email('ana@barberhub.test');

        $this->assertFalse(User::createStaff($id, $name, $email, 'hash')->platformOperator);
        $this->assertFalse(User::createInvited($id, $name, $email, 'hash')->platformOperator);

        $operator = User::createPlatformOperator($id, $name, $email, 'hash');

        $this->assertTrue($operator->platformOperator);
        $this->assertTrue($operator->mustChangePassword);
        $this->assertTrue($operator->changePassword('other')->platformOperator);
        $this->assertFalse($operator->changePassword('other')->mustChangePassword);
        $this->assertTrue($operator->updateProfile($name, $email, new Phone('8888-0000'))->platformOperator);
        $this->assertFalse(User::reconstitute($id, $name, $email, null, 'hash', true, null, null, false)->platformOperator);
    }
}
