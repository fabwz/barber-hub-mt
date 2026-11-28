<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\RegisterPlatformOperatorCommand;
use Src\Identity\Users\Application\UseCases\RegisterPlatformOperator;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\InMemoryUserRepository;

final class RegisterPlatformOperatorTest extends TestCase
{
    private InMemoryUserRepository $users;

    private RegisterPlatformOperator $registerPlatformOperator;

    /** Wire the use case to in-memory ports. */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->registerPlatformOperator = new RegisterPlatformOperator($this->users, new FakePasswordHasher);
    }

    /** The account is an operator, active, hashed and marked to change its password; it has neither phone nor acceptances (RF-55, PL-01). */
    public function test_the_account_is_an_operator_that_must_change_its_password(): void
    {
        $user = $this->registerPlatformOperator->execute(new RegisterPlatformOperatorCommand('Operadora Plataforma', ' Operadora@barberhub.test ', 'Temporal#1'));

        $this->assertTrue($user->platformOperator);
        $this->assertTrue($user->mustChangePassword);
        $this->assertTrue($user->active);
        $this->assertSame('operadora@barberhub.test', $user->email->value);
        $this->assertSame('hashed:Temporal#1', $user->passwordHash);
        $this->assertNull($user->phone);
        $this->assertSame($user, $this->users->findById($user->id));
    }

    /** An email that already has an account is refused, so an existing account is never marked. */
    public function test_a_repeated_email_is_refused(): void
    {
        $this->registerPlatformOperator->execute(new RegisterPlatformOperatorCommand('Operadora', 'operadora@barberhub.test', 'Temporal#1'));

        $this->expectException(EmailAlreadyRegisteredException::class);

        $this->registerPlatformOperator->execute(new RegisterPlatformOperatorCommand('Otra', 'operadora@barberhub.test', 'Temporal#2'));
    }

    /** A temporary password that misses a requirement of SE-07 is refused. */
    public function test_the_temporary_password_must_meet_the_policy(): void
    {
        $this->expectException(InvalidValueException::class);

        $this->registerPlatformOperator->execute(new RegisterPlatformOperatorCommand('Operadora', 'operadora@barberhub.test', 'debil'));
    }
}
