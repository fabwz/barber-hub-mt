<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\IssuedPasswordReset;
use Src\Identity\Users\Domain\Contracts\PasswordResetTokens;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\PasswordResetToken;
use Src\Shared\Domain\Contracts\Clock;

final readonly class IssuePasswordReset
{
    /** Receive the account repository, the store of the links and the clock. */
    public function __construct(
        private UserRepository $users,
        private PasswordResetTokens $tokens,
        private Clock $clock,
    ) {}

    /**
     * Create the link of an active account, valid for 60 minutes (SE-06), and forget the one it had; null when there is no such account,
     * or it is deactivated. Only the hash of the token is stored: the plain one goes back to the caller once, to be written in the email.
     */
    public function execute(string $email): ?IssuedPasswordReset
    {
        $user = $this->users->findByEmail(new Email($email));

        if ($user === null || ! $user->active) {
            return null;
        }

        $token = PasswordResetToken::generate();
        $expiresAt = $this->clock->now()->modify('+'.PasswordResetToken::TTL_MINUTES.' minutes');

        $this->tokens->replace($user->id, $token->hash(), $expiresAt);

        return new IssuedPasswordReset($user->name->value, $user->email->value, $token->plain, $expiresAt);
    }
}
