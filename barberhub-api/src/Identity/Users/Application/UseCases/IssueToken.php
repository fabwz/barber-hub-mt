<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\IssuedToken;
use Src\Identity\Users\Application\DTOs\IssueTokenCommand;
use Src\Identity\Users\Domain\Contracts\LoginAttempts;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\TokenIssuer;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Exceptions\InvalidCredentialsException;
use Src\Identity\Users\Domain\Exceptions\TooManyLoginAttemptsException;
use Src\Identity\Users\Domain\ValueObjects\Email;

final readonly class IssueToken
{
    /** Receive the user repository, the password hashing, the token and the login attempts ports. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwords,
        private TokenIssuer $tokens,
        private LoginAttempts $attempts,
    ) {}

    /**
     * Issue a token when the credentials belong to an active account and the login is not locked (SE-05).
     *
     * @throws TooManyLoginAttemptsException
     * @throws InvalidCredentialsException
     */
    public function execute(IssueTokenCommand $command): IssuedToken
    {
        $email = new Email($command->email);

        $lockedFor = $this->attempts->secondsUntilAllowed($email, $command->ipAddress);

        if ($lockedFor > 0) {
            throw TooManyLoginAttemptsException::create($lockedFor);
        }

        $user = $this->users->findByEmail($email);
        $passwordMatches = $this->passwords->check($command->password, $user?->passwordHash);

        if ($user === null || ! $passwordMatches || ! $user->active) {
            $this->attempts->recordFailure($email, $command->ipAddress);

            throw InvalidCredentialsException::create();
        }

        $this->attempts->reset($email, $command->ipAddress);

        return new IssuedToken($this->tokens->issue($user->id, $command->deviceName));
    }
}
