<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\ChangePasswordCommand;
use Src\Identity\Users\Application\UseCases\ChangePassword;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\CurrentPasswordIncorrectException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\FakeTokenRevoker;
use Tests\Support\Identity\InMemoryUserRepository;

final class ChangePasswordTest extends TestCase
{
    private InMemoryUserRepository $users;

    private FakePasswordHasher $passwords;

    private FakeTokenRevoker $tokens;

    private User $kevin;

    /** Start with a collaborator whose temporary password is "Temporal#2026". */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->passwords = new FakePasswordHasher;
        $this->tokens = new FakeTokenRevoker;
        $this->kevin = User::createStaff($this->users->nextId(), new UserName('Kevin Solano'), new Email('kevin@barberhub.test'), $this->passwords->hash('Temporal#2026'));
        $this->users->save($this->kevin);
    }

    /** The new password is hashed, the temporary state ends and every other session is closed (RF-40, UP-04, SE-01). */
    public function test_changes_the_password_and_closes_the_other_sessions(): void
    {
        $this->change('Temporal#2026', 'Nueva#Clave9', '7');

        $stored = $this->users->findById($this->kevin->id);
        $this->assertNotNull($stored);
        $this->assertSame($this->passwords->hash('Nueva#Clave9'), $stored->passwordHash);
        $this->assertFalse($stored->mustChangePassword);
        $this->assertSame([[$this->kevin->id->value, '7']], $this->tokens->revokedExcept);
        $this->assertSame([], $this->tokens->revokedAll);
    }

    /** A wrong current password is refused and nothing changes. */
    public function test_rejects_a_wrong_current_password(): void
    {
        try {
            $this->change('Otra#Clave1', 'Nueva#Clave9', '7');
            $this->fail('A wrong current password was accepted.');
        } catch (CurrentPasswordIncorrectException $exception) {
            $this->assertSame('identity.current_password_incorrect', $exception->translationKey());
            $this->assertSame('contrasena_actual', $exception->field());
        }

        $this->assertSame($this->kevin, $this->users->findById($this->kevin->id));
        $this->assertSame([], $this->tokens->revokedExcept);
    }

    /** A new password that misses a requirement of SE-07 is refused and nothing changes. */
    public function test_rejects_a_new_password_that_breaks_the_policy(): void
    {
        try {
            $this->change('Temporal#2026', 'corta', '7');
            $this->fail('A weak password was accepted.');
        } catch (InvalidValueException $exception) {
            $this->assertSame('identity.password_too_short', $exception->translationKey());
        }

        $this->assertSame($this->kevin, $this->users->findById($this->kevin->id));
        $this->assertSame([], $this->tokens->revokedExcept);
    }

    /** Run the use case. */
    private function change(string $current, string $new, string $tokenId): void
    {
        new ChangePassword($this->users, $this->passwords, $this->tokens)
            ->execute(new ChangePasswordCommand($this->kevin->id->value, $current, $new, $tokenId));
    }
}
