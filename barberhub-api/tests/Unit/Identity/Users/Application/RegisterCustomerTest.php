<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\RegisterCustomerCommand;
use Src\Identity\Users\Application\UseCases\RegisterCustomer;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\InMemoryUserRepository;

final class RegisterCustomerTest extends TestCase
{
    private InMemoryUserRepository $users;

    private RegisterCustomer $registerCustomer;

    /** Wire the use case to in-memory ports. */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->registerCustomer = new RegisterCustomer($this->users, new FakePasswordHasher);
    }

    /** The account is stored active, with a hashed password and the instant of each acceptance. */
    public function test_account_is_registered_with_a_hashed_password_and_both_acceptances(): void
    {
        $termsAt = new DateTimeImmutable('2026-10-19 10:00:00');
        $privacyAt = new DateTimeImmutable('2026-10-19 10:00:05');

        $user = $this->registerCustomer->execute($this->command(termsAt: $termsAt, privacyAt: $privacyAt));

        $this->assertSame('Ana Mora', $user->name->value);
        $this->assertSame('ana@barberhub.test', $user->email->value);
        $this->assertSame('8888-0000', $user->phone?->value);
        $this->assertTrue($user->active);
        $this->assertSame('hashed:Clave#segura1', $user->passwordHash);
        $this->assertSame($termsAt, $user->termsAcceptedAt);
        $this->assertSame($privacyAt, $user->privacyAcceptedAt);
        $this->assertEquals($user, $this->users->findByEmail(new Email('ana@barberhub.test')));
    }

    /** A second account with the same email, however it is spelled, is refused and the first one is kept. */
    public function test_repeated_email_is_refused(): void
    {
        $this->registerCustomer->execute($this->command());

        $this->expectException(EmailAlreadyRegisteredException::class);

        try {
            $this->registerCustomer->execute($this->command(email: ' ANA@barberhub.test '));
        } finally {
            $this->assertSame(1, $this->users->count());
        }
    }

    /** A password that misses a requirement is refused before anything is stored. */
    #[DataProvider('weakPasswords')]
    public function test_weak_password_is_refused(string $password, string $key): void
    {
        try {
            $this->registerCustomer->execute($this->command(password: $password));
            $this->fail('A weak password was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame($key, $exception->translationKey());
            $this->assertSame(0, $this->users->count());
        }
    }

    /**
     * Passwords that break one requirement of SE-07.
     *
     * @return array<string, array{string, string}>
     */
    public static function weakPasswords(): array
    {
        return [
            'too short' => ['Ab1!', 'identity.password_too_short'],
            'without uppercase' => ['abcdef1!', 'identity.password_without_uppercase'],
            'without lowercase' => ['ABCDEF1!', 'identity.password_without_lowercase'],
            'without number' => ['Abcdefg!', 'identity.password_without_number'],
            'without symbol' => ['Abcdefg1', 'identity.password_without_symbol'],
        ];
    }

    /** A blank name, phone or a malformed email is refused before anything is stored. */
    #[DataProvider('invalidIdentities')]
    public function test_invalid_identity_is_refused(string $name, string $email, string $phone, string $key): void
    {
        try {
            $this->registerCustomer->execute($this->command(name: $name, email: $email, phone: $phone));
            $this->fail('An invalid account was registered.');
        } catch (InvalidValueException $exception) {
            $this->assertSame($key, $exception->translationKey());
            $this->assertSame(0, $this->users->count());
        }
    }

    /**
     * Names, emails and phones that break their invariants.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidIdentities(): array
    {
        return [
            'blank name' => ['   ', 'ana@barberhub.test', '8888-0000', 'identity.name_invalid'],
            'malformed email' => ['Ana Mora', 'ana.barberhub.test', '8888-0000', 'identity.email_invalid'],
            'blank phone' => ['Ana Mora', 'ana@barberhub.test', '   ', 'shared.phone_invalid'],
        ];
    }

    /** Build the command of a customer, overriding only what a scenario changes. */
    private function command(
        string $name = 'Ana Mora',
        string $email = 'ana@barberhub.test',
        string $phone = '8888-0000',
        string $password = 'Clave#segura1',
        ?DateTimeImmutable $termsAt = null,
        ?DateTimeImmutable $privacyAt = null,
    ): RegisterCustomerCommand {
        $now = new DateTimeImmutable('2026-10-19 10:00:00');

        return new RegisterCustomerCommand($name, $email, $phone, $password, $termsAt ?? $now, $privacyAt ?? $now);
    }
}
