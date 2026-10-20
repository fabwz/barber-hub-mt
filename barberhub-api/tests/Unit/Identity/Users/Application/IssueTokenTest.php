<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\IssuedToken;
use Src\Identity\Users\Application\DTOs\IssueTokenCommand;
use Src\Identity\Users\Application\UseCases\IssueToken;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\InvalidCredentialsException;
use Src\Identity\Users\Domain\Exceptions\TooManyLoginAttemptsException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\ValueObjects\Phone;
use Tests\Support\Identity\FakeLoginAttempts;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\FakeTokenIssuer;
use Tests\Support\Identity\InMemoryUserRepository;

final class IssueTokenTest extends TestCase
{
    private const string IP = '203.0.113.7';

    private InMemoryUserRepository $users;

    private FakePasswordHasher $passwords;

    private FakeTokenIssuer $tokens;

    private FakeLoginAttempts $attempts;

    /** Start every scenario without accounts nor tokens. */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->passwords = new FakePasswordHasher;
        $this->tokens = new FakeTokenIssuer;
        $this->attempts = new FakeLoginAttempts;
    }

    /** Valid credentials get a token for the device and clear the failed attempts. */
    public function test_issues_a_token_and_resets_the_attempts(): void
    {
        $user = $this->account();

        $token = $this->login(' ANA@barberhub.test ', 'Clave#segura1');

        $this->assertSame('1|token-de-prueba', $token->plainTextToken);
        $this->assertSame([[$user->id->value, 'Tablet del local']], $this->tokens->issued);
        $this->assertSame([['ana@barberhub.test', self::IP]], $this->attempts->resets);
        $this->assertSame([], $this->attempts->failures);
    }

    /** A wrong password, an unknown email and an inactive account fail in the same way and count as a failure. */
    #[DataProvider('invalidCredentials')]
    public function test_rejects_invalid_credentials_without_saying_why(string $email, string $password, bool $active): void
    {
        $this->account($active);

        try {
            $this->login($email, $password);
            $this->fail('Invalid credentials received a token.');
        } catch (InvalidCredentialsException $exception) {
            $this->assertSame('identity.invalid_credentials', $exception->translationKey());
            $this->assertSame([], $this->tokens->issued);
            $this->assertSame([[$email, self::IP]], $this->attempts->failures);
            $this->assertSame([], $this->attempts->resets);
        }
    }

    /**
     * Credentials that must not receive a token.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function invalidCredentials(): array
    {
        return [
            'wrong password' => ['ana@barberhub.test', 'OtraClave#2', true],
            'unknown email' => ['nadie@barberhub.test', 'Clave#segura1', true],
            'inactive account' => ['ana@barberhub.test', 'Clave#segura1', false],
        ];
    }

    /** An unknown email still runs one password check, so the time does not reveal which emails exist. */
    public function test_unknown_email_still_checks_a_password(): void
    {
        try {
            $this->login('nadie@barberhub.test', 'Clave#segura1');
            $this->fail('An unknown email received a token.');
        } catch (InvalidCredentialsException) {
            $this->assertSame(1, $this->passwords->checks);
        }
    }

    /** While the login is locked, even the right password is refused, without checking it nor counting a failure. */
    public function test_locked_login_is_refused_even_with_the_right_password(): void
    {
        $this->account();
        $this->attempts->lockedFor = 840;

        try {
            $this->login('ana@barberhub.test', 'Clave#segura1');
            $this->fail('A locked login received a token.');
        } catch (TooManyLoginAttemptsException $exception) {
            $this->assertSame('identity.too_many_login_attempts', $exception->translationKey());
            $this->assertSame(840, $exception->retryAfterSeconds());
            $this->assertSame(['minutes' => 14, 'seconds' => 840], $exception->parameters());
            $this->assertSame(0, $this->passwords->checks);
            $this->assertSame([], $this->tokens->issued);
            $this->assertSame([], $this->attempts->failures);
        }
    }

    /** The minutes to retry round up, never below one. */
    public function test_minutes_round_up(): void
    {
        $this->assertSame(1, TooManyLoginAttemptsException::create(1)->parameters()['minutes']);
        $this->assertSame(2, TooManyLoginAttemptsException::create(61)->parameters()['minutes']);
        $this->assertSame(15, TooManyLoginAttemptsException::create(900)->parameters()['minutes']);
    }

    /** Store an account whose password is "Clave#segura1". */
    private function account(bool $active = true): User
    {
        $user = User::reconstitute(
            $this->users->nextId(),
            new UserName('Ana Mora'),
            new Email('ana@barberhub.test'),
            new Phone('8888-0000'),
            $this->passwords->hash('Clave#segura1'),
            $active,
            null,
            null,
        );
        $this->users->save($user);

        return $user;
    }

    /** Run the use case from a tablet. */
    private function login(string $email, string $password): IssuedToken
    {
        return new IssueToken($this->users, $this->passwords, $this->tokens, $this->attempts)
            ->execute(new IssueTokenCommand($email, $password, 'Tablet del local', self::IP));
    }
}
