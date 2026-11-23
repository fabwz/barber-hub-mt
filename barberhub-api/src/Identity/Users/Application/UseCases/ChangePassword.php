<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\ChangePasswordCommand;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\TokenRevoker;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Exceptions\CurrentPasswordIncorrectException;
use Src\Identity\Users\Domain\Exceptions\NewPasswordSameAsCurrentException;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ChangePassword
{
    /** Receive the user repository, the password hashing and the token revoker ports. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwords,
        private TokenRevoker $tokens,
    ) {}

    /**
     * Replace the password after checking the current one (RF-40). The new one follows SE-07 and is stored hashed (SE-01);
     * a temporary password stops being one. The other sessions of the person are closed and the one that asks stays open.
     *
     * The new password must differ from the current one, checked against the stored hash: it is mandatory when the
     * current one is the temporary password (decision of the owner, an extension of RF-40 and SE-07).
     *
     * @throws CurrentPasswordIncorrectException
     * @throws NewPasswordSameAsCurrentException
     * @throws InvalidValueException
     */
    public function execute(ChangePasswordCommand $command): void
    {
        $userId = new UserId($command->userId);
        $user = $this->users->findById($userId);

        if ($user === null || ! $this->passwords->check($command->currentPassword, $user->passwordHash)) {
            throw CurrentPasswordIncorrectException::create();
        }

        $newPassword = new PlainPassword($command->newPassword);

        if ($this->passwords->check($newPassword->value, $user->passwordHash)) {
            throw NewPasswordSameAsCurrentException::create();
        }

        $this->users->save($user->changePassword($this->passwords->hash($newPassword->value)));
        $this->tokens->revokeAllExcept($userId, $command->currentTokenId);
    }
}
