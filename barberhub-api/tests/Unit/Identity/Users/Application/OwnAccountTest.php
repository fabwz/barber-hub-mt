<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\UpdateAccountCommand;
use Src\Identity\Users\Application\UseCases\ShowAccount;
use Src\Identity\Users\Application\UseCases\UpdateAccount;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\AccountNotFoundException;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Phone;
use Tests\Support\Identity\InMemoryUserRepository;

final class OwnAccountTest extends TestCase
{
    private const string UNKNOWN = '0192f0c4-0000-7000-8000-999999999999';

    private InMemoryUserRepository $users;

    private User $ana;

    private User $luis;

    /** Start with two accounts. */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $when = new DateTimeImmutable('2026-10-19 10:00:00');
        $this->ana = User::create($this->users->nextId(), new UserName('Ana Mora'), new Email('ana@barberhub.test'), new Phone('8888-0000'), 'hash', $when, $when);
        $this->luis = User::create($this->users->nextId(), new UserName('Luis Vega'), new Email('luis@barberhub.test'), new Phone('7777-1111'), 'hash', $when, $when);
        $this->users->save($this->ana);
        $this->users->save($this->luis);
    }

    /** The account of the person is read as it is stored. */
    public function test_shows_the_account(): void
    {
        $user = new ShowAccount($this->users)->execute($this->ana->id->value);

        $this->assertSame('ana@barberhub.test', $user->email->value);
    }

    /** An account that does not exist is not found. */
    public function test_an_unknown_account_is_not_found(): void
    {
        $this->expectException(AccountNotFoundException::class);

        new ShowAccount($this->users)->execute(self::UNKNOWN);
    }

    /** The name, phone and email change; the hash, the acceptances and the temporary state stay. */
    public function test_updates_only_the_profile(): void
    {
        $user = new UpdateAccount($this->users)->execute(new UpdateAccountCommand($this->ana->id->value, 'Ana M.', 'nueva@barberhub.test', '8999-1234'));

        $stored = $this->users->findById($this->ana->id);
        $this->assertSame($user, $stored);
        $this->assertSame('Ana M.', $user->name->value);
        $this->assertSame('nueva@barberhub.test', $user->email->value);
        $this->assertSame('8999-1234', $user->phone?->value);
        $this->assertSame('hash', $user->passwordHash);
        $this->assertFalse($user->mustChangePassword);
        $this->assertSame($this->ana->termsAcceptedAt, $user->termsAcceptedAt);
    }

    /** The own email is not a conflict. */
    public function test_the_own_email_is_accepted(): void
    {
        $user = new UpdateAccount($this->users)->execute(new UpdateAccountCommand($this->ana->id->value, 'Ana', 'ANA@barberhub.test', '8888-0000'));

        $this->assertSame('ana@barberhub.test', $user->email->value);
    }

    /** The email of another account is refused on its field and nothing changes. */
    public function test_the_email_of_another_account_is_refused(): void
    {
        try {
            new UpdateAccount($this->users)->execute(new UpdateAccountCommand($this->ana->id->value, 'Otra', 'luis@barberhub.test', '8888-0000'));
            $this->fail('The email of another account was accepted.');
        } catch (EmailAlreadyRegisteredException $exception) {
            $this->assertSame('correo', $exception->field());
        }

        $this->assertSame('Ana Mora', $this->users->findById($this->ana->id)?->name->value);
    }

    /** An invalid value is refused by its value object. */
    public function test_an_invalid_value_is_refused(): void
    {
        $this->expectException(InvalidValueException::class);

        new UpdateAccount($this->users)->execute(new UpdateAccountCommand($this->ana->id->value, '', 'ana@barberhub.test', '8888-0000'));
    }

    /** An account that does not exist cannot be updated. */
    public function test_an_unknown_account_cannot_be_updated(): void
    {
        $this->expectException(AccountNotFoundException::class);

        new UpdateAccount($this->users)->execute(new UpdateAccountCommand(self::UNKNOWN, 'Ana', 'ana@barberhub.test', '8888-0000'));
    }
}
