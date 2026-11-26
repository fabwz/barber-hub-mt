<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Application\DTOs\IssuedPasswordReset;
use Src\Identity\Users\Application\DTOs\RequestPasswordResetCommand;
use Src\Identity\Users\Application\DTOs\ResetPasswordCommand;
use Src\Identity\Users\Application\UseCases\IssuePasswordReset;
use Src\Identity\Users\Application\UseCases\RequestPasswordReset;
use Src\Identity\Users\Application\UseCases\ResetPassword;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Events\PasswordResetRequested;
use Src\Identity\Users\Domain\Exceptions\InvalidPasswordResetLinkException;
use Src\Identity\Users\Domain\Exceptions\TooManyPasswordResetRequestsException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Tests\Support\Identity\FakePasswordHasher;
use Tests\Support\Identity\FakePasswordResetLimiter;
use Tests\Support\Identity\FakeTokenRevoker;
use Tests\Support\Identity\InMemoryPasswordResetTokens;
use Tests\Support\Identity\InMemoryUserRepository;
use Tests\Support\Shared\MutableClock;
use Tests\Support\Shared\RecordingDomainEvents;
use Tests\Support\Shared\RecordingTransactionRunner;

final class PasswordResetTest extends TestCase
{
    private InMemoryUserRepository $users;

    private FakePasswordHasher $passwords;

    private InMemoryPasswordResetTokens $tokens;

    private FakeTokenRevoker $revoker;

    private MutableClock $clock;

    private FakePasswordResetLimiter $limiter;

    private RecordingDomainEvents $events;

    private User $kevin;

    /** Start with a collaborator whose temporary password is "Temporal#2026". */
    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository;
        $this->passwords = new FakePasswordHasher;
        $this->tokens = new InMemoryPasswordResetTokens;
        $this->revoker = new FakeTokenRevoker;
        $this->clock = new MutableClock(new DateTimeImmutable('2026-11-26 10:00:00'));
        $this->limiter = new FakePasswordResetLimiter;
        $this->events = new RecordingDomainEvents;
        $this->kevin = User::createStaff($this->users->nextId(), new UserName('Kevin Solano'), new Email('kevin@barberhub.test'), $this->passwords->hash('Temporal#2026'));
        $this->users->save($this->kevin);
    }

    /** The request announces the email and never looks for the account, so an unknown email does exactly the same (SE-06). */
    public function test_a_request_announces_any_email_the_same_way(): void
    {
        $this->request('KEVIN@barberhub.test');
        $this->request('nadie@barberhub.test');

        $this->assertEquals(
            [new PasswordResetRequested('kevin@barberhub.test'), new PasswordResetRequested('nadie@barberhub.test')],
            $this->events->of(PasswordResetRequested::class),
        );
        $this->assertSame([['kevin@barberhub.test', '10.0.0.1'], ['nadie@barberhub.test', '10.0.0.1']], $this->limiter->hits);
    }

    /** A request over the limit is refused with the wait, and announces nothing. */
    public function test_a_request_over_the_limit_is_refused_with_the_wait(): void
    {
        $this->limiter->waitFor = 1800;

        try {
            $this->request('kevin@barberhub.test');
            $this->fail('A request over the limit was accepted.');
        } catch (TooManyPasswordResetRequestsException $exception) {
            $this->assertSame(1800, $exception->retryAfterSeconds());
            $this->assertSame(30, $exception->parameters()['minutes']);
        }

        $this->assertSame([], $this->events->all());
    }

    /** The link is valid 60 minutes and only its hash is stored (SE-06). */
    public function test_issuing_stores_only_the_hash_and_lasts_sixty_minutes(): void
    {
        $issued = $this->issue('kevin@barberhub.test');

        $this->assertNotNull($issued);
        $this->assertSame('Kevin Solano', $issued->name);
        $this->assertSame([hash('sha256', $issued->token)], $this->tokens->storedHashes());
        $this->assertNotContains($issued->token, $this->tokens->storedHashes());
        $this->assertEquals(new DateTimeImmutable('2026-11-26 11:00:00'), $issued->expiresAt);
        $this->assertEquals($issued->expiresAt, $this->tokens->expiresAt($this->kevin->id));
    }

    /** An unknown or deactivated account gets no link, and the caller cannot tell them apart. */
    public function test_no_link_for_an_unknown_or_deactivated_account(): void
    {
        $this->assertNull($this->issue('nadie@barberhub.test'));

        $this->users->save(User::reconstitute($this->kevin->id, $this->kevin->name, $this->kevin->email, null, $this->kevin->passwordHash, false, null, null, true));
        $this->assertNull($this->issue('kevin@barberhub.test'));
        $this->assertSame(0, $this->tokens->count());
    }

    /** A new link replaces the one pending, which stops working (SE-06). */
    public function test_a_new_link_invalidates_the_previous_one(): void
    {
        $first = $this->issue('kevin@barberhub.test');
        $second = $this->issue('kevin@barberhub.test');
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame($first->token, $second->token);

        $this->assertInvalidLink($first->token);
        $this->reset($second->token, 'Nueva#Clave9');
        $this->assertSame($this->passwords->hash('Nueva#Clave9'), $this->users->findById($this->kevin->id)?->passwordHash);
    }

    /** The new password is hashed, stops being temporary and every session and token of the account is closed (RF-41, SE-06, UP-04). */
    public function test_reset_changes_the_password_and_closes_every_session(): void
    {
        $issued = $this->issue('kevin@barberhub.test');
        $this->assertNotNull($issued);

        $this->reset($issued->token, 'Nueva#Clave9');

        $stored = $this->users->findById($this->kevin->id);
        $this->assertNotNull($stored);
        $this->assertSame($this->passwords->hash('Nueva#Clave9'), $stored->passwordHash);
        $this->assertFalse($stored->mustChangePassword);
        $this->assertSame([$this->kevin->id->value], $this->revoker->revokedAll);
        $this->assertSame([], $this->revoker->revokedExcept);
    }

    /** The link works once. */
    public function test_the_link_cannot_be_used_twice(): void
    {
        $issued = $this->issue('kevin@barberhub.test');
        $this->assertNotNull($issued);
        $this->reset($issued->token, 'Nueva#Clave9');

        $this->assertInvalidLink($issued->token);
        $this->assertSame([$this->kevin->id->value], $this->revoker->revokedAll);
    }

    /** The link works until minute 59 and stops at minute 60 (SE-06). */
    public function test_the_link_expires_after_sixty_minutes(): void
    {
        $issued = $this->issue('kevin@barberhub.test');
        $this->assertNotNull($issued);

        $this->clock->moveTo(new DateTimeImmutable('2026-11-26 11:00:00'));
        $this->assertInvalidLink($issued->token);

        $this->clock->moveTo(new DateTimeImmutable('2026-11-26 10:00:00'));
        $fresh = $this->issue('kevin@barberhub.test');
        $this->assertNotNull($fresh);
        $this->clock->moveTo(new DateTimeImmutable('2026-11-26 10:59:59'));
        $this->reset($fresh->token, 'Nueva#Clave9');
        $this->assertSame([$this->kevin->id->value], $this->revoker->revokedAll);
    }

    /** A weak password is refused before the link is used, so the person can try again with the same link (SE-07). */
    public function test_a_weak_password_is_refused_and_keeps_the_link(): void
    {
        $issued = $this->issue('kevin@barberhub.test');
        $this->assertNotNull($issued);

        try {
            $this->reset($issued->token, 'debil');
            $this->fail('A weak password was accepted.');
        } catch (InvalidValueException) {
            $this->assertSame(1, $this->tokens->count());
        }

        $this->assertSame([], $this->revoker->revokedAll);
        $this->reset($issued->token, 'Nueva#Clave9');
        $this->assertSame(0, $this->tokens->count());
    }

    /** An unknown token is the same refusal as an expired or used one. */
    public function test_an_unknown_token_is_refused(): void
    {
        $this->assertInvalidLink(str_repeat('a', 64));
        $this->assertSame([], $this->revoker->revokedAll);
    }

    private function request(string $email): void
    {
        new RequestPasswordReset($this->limiter, $this->events)->execute(new RequestPasswordResetCommand($email, '10.0.0.1'));
    }

    private function issue(string $email): ?IssuedPasswordReset
    {
        return new IssuePasswordReset($this->users, $this->tokens, $this->clock)->execute($email);
    }

    private function reset(string $token, string $password): void
    {
        new ResetPassword($this->users, $this->passwords, $this->tokens, $this->revoker, $this->clock, new RecordingTransactionRunner)
            ->execute(new ResetPasswordCommand($token, $password));
    }

    private function assertInvalidLink(string $token): void
    {
        try {
            $this->reset($token, 'Nueva#Clave9');
            $this->fail('A link that must not work was accepted.');
        } catch (InvalidPasswordResetLinkException $exception) {
            $this->assertSame('identity.invalid_password_reset_link', $exception->translationKey());
            $this->assertSame('token', $exception->field());
        }
    }
}
