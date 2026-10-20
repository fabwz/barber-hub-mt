<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\RegisterStaffAccountCommand;
use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Identity\Users\Application\UseCases\RegisterStaffAccount;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\InMemoryUserRepository;

final class RegisterStaffAccountTest extends TestCase
{
    private InMemoryUserRepository $users;

    private RegisterStaffAccount $registerStaffAccount;

    /** Wire the use case to in-memory ports. */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->registerStaffAccount = new RegisterStaffAccount($this->users, new FakePasswordHasher);
    }

    /** The account is active, hashed, marked to change its password and has neither phone nor acceptances. */
    public function test_account_is_created_active_and_must_change_its_password(): void
    {
        $user = $this->registerStaffAccount->execute(new RegisterStaffAccountCommand('Kevin Solano', ' Kevin@barberhub.test ', 'Temporal#1'));

        $this->assertSame('Kevin Solano', $user->name->value);
        $this->assertSame('kevin@barberhub.test', $user->email->value);
        $this->assertSame('hashed:Temporal#1', $user->passwordHash);
        $this->assertTrue($user->active);
        $this->assertTrue($user->mustChangePassword);
        $this->assertNull($user->phone);
        $this->assertNull($user->termsAcceptedAt);
        $this->assertNull($user->privacyAcceptedAt);
    }

    /** A temporary password that misses a requirement of SE-07 is refused and nothing is stored. */
    public function test_temporary_password_must_meet_the_password_policy(): void
    {
        try {
            $this->registerStaffAccount->execute(new RegisterStaffAccountCommand('Kevin Solano', 'kevin@barberhub.test', 'temporal'));
            $this->fail('A weak temporary password was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('identity.password_without_uppercase', $exception->translationKey());
            $this->assertSame(0, $this->users->count());
        }
    }

    /** An email that already has an account is refused: linking it is the invitation flow. */
    public function test_repeated_email_is_refused(): void
    {
        $this->registerStaffAccount->execute(new RegisterStaffAccountCommand('Kevin Solano', 'kevin@barberhub.test', 'Temporal#1'));

        $this->expectException(EmailAlreadyRegisteredException::class);

        $this->registerStaffAccount->execute(new RegisterStaffAccountCommand('Otro Kevin', 'KEVIN@barberhub.test', 'Temporal#2'));
    }

    /** The summaries carry the name and email of the accounts asked for, and none of the others. */
    public function test_summaries_describe_only_the_accounts_asked_for(): void
    {
        $kevin = $this->registerStaffAccount->execute(new RegisterStaffAccountCommand('Kevin Solano', 'kevin@barberhub.test', 'Temporal#1'));
        $this->registerStaffAccount->execute(new RegisterStaffAccountCommand('Ana Mora', 'ana@barberhub.test', 'Temporal#1'));
        $listAccountSummaries = new ListAccountSummaries($this->users);

        $summaries = $listAccountSummaries->execute([$kevin->id->value]);

        $this->assertCount(1, $summaries);
        $this->assertSame([$kevin->id->value, 'Kevin Solano', 'kevin@barberhub.test'], [$summaries[0]->userId, $summaries[0]->name, $summaries[0]->email]);
        $this->assertSame([], $listAccountSummaries->execute([]));
    }
}
