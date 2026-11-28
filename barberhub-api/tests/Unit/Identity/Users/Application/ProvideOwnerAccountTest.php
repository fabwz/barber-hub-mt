<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\ProvideOwnerAccountCommand;
use Src\Identity\Users\Application\DTOs\RegisterStaffAccountCommand;
use Src\Identity\Users\Application\UseCases\ProvideOwnerAccount;
use Src\Identity\Users\Application\UseCases\RegisterStaffAccount;
use Src\Identity\Users\Domain\Contracts\TemporaryPasswordGenerator;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\InMemoryUserRepository;

final class ProvideOwnerAccountTest extends TestCase
{
    private InMemoryUserRepository $users;

    private ProvideOwnerAccount $provide;

    /** Wire the use case to in-memory ports and a generator that always gives the same valid password. */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->provide = new ProvideOwnerAccount($this->users, new FakePasswordHasher, new class implements TemporaryPasswordGenerator
        {
            public function generate(): string
            {
                return 'Temporal#Clave1';
            }
        });
    }

    /** A new email gets an active account with the temporary password, stored only hashed, and the password comes back once (UP-04, UP-05). */
    public function test_a_new_email_gets_an_account_with_a_temporary_password(): void
    {
        $account = $this->provide->execute(new ProvideOwnerAccountCommand('Rodrigo Mora', ' Rodrigo@Barberhub.test ', '8888-0000'));

        $this->assertTrue($account->created());
        $this->assertSame('Temporal#Clave1', $account->temporaryPassword);
        $this->assertSame('rodrigo@barberhub.test', $account->email);

        $user = $this->users->findByEmail(new Email('rodrigo@barberhub.test'));
        $this->assertNotNull($user);
        $this->assertSame($user->id->value, $account->userId);
        $this->assertSame('hashed:Temporal#Clave1', $user->passwordHash);
        $this->assertTrue($user->mustChangePassword);
        $this->assertTrue($user->active);
        $this->assertFalse($user->platformOperator);
        $this->assertSame('8888-0000', $user->phone?->value);
        $this->assertNull($user->termsAcceptedAt);
    }

    /** An email with an account keeps it untouched: same password hash, name and phone, and no password to show (UP-05). */
    public function test_an_existing_account_is_left_as_it_is(): void
    {
        $existing = new RegisterStaffAccount($this->users, new FakePasswordHasher)->execute(new RegisterStaffAccountCommand('Kevin Solano', 'kevin@barberhub.test', 'Clave#Previa1'));

        $account = $this->provide->execute(new ProvideOwnerAccountCommand('Otro Nombre', 'KEVIN@barberhub.test', '8888-0000'));

        $this->assertFalse($account->created());
        $this->assertNull($account->temporaryPassword);
        $this->assertSame($existing->id->value, $account->userId);
        $this->assertSame(1, $this->users->count());
        $this->assertEquals($existing, $this->users->findByEmail(new Email('kevin@barberhub.test')));
    }

    /** Invalid data creates nothing. */
    public function test_invalid_data_creates_nothing(): void
    {
        try {
            $this->provide->execute(new ProvideOwnerAccountCommand('   ', 'rodrigo@barberhub.test', '8888-0000'));
            $this->fail('A blank name was accepted.');
        } catch (InvalidValueException) {
            $this->assertSame(0, $this->users->count());
        }
    }
}
