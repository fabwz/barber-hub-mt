<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\ResetPasswordCommand;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\PasswordResetTokens;
use Src\Identity\Users\Domain\Contracts\TokenRevoker;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Exceptions\InvalidPasswordResetLinkException;
use Src\Identity\Users\Domain\ValueObjects\PasswordResetToken;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Shared\Domain\Contracts\Clock;
use Src\Shared\Domain\Contracts\TransactionRunner;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class ResetPassword
{
    /** Receive the account repository, the password hashing, the store of the links, the token revoker, the clock and the transaction port. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwords,
        private PasswordResetTokens $tokens,
        private TokenRevoker $revoker,
        private Clock $clock,
        private TransactionRunner $transactions,
    ) {}

    /**
     * Set a new password with the link (RF-41). The password follows SE-07 and is checked before the link is used, so a weak one does not
     * burn it. The link is used once (SE-06); the new password is stored hashed (SE-01) and stops being temporary; every session and token of
     * the account is closed. The link, the password and the sessions change together or not at all.
     *
     * @throws InvalidValueException
     * @throws InvalidPasswordResetLinkException
     */
    public function execute(ResetPasswordCommand $command): void
    {
        $password = new PlainPassword($command->newPassword);
        $tokenHash = PasswordResetToken::fromPlain($command->token)->hash();

        $this->transactions->run(function () use ($tokenHash, $password): void {
            $userId = $this->tokens->consume($tokenHash, $this->clock->now());
            $user = $userId === null ? null : $this->users->findById($userId);

            if ($user === null || ! $user->active) {
                throw InvalidPasswordResetLinkException::create();
            }

            $this->users->save($user->changePassword($this->passwords->hash($password->value)));
            $this->revoker->revokeAll($user->id);
        });
    }
}
