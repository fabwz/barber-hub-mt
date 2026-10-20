<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\RegisterStaffAccountCommand;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Domain\ValueObjects\UserName;

final readonly class RegisterStaffAccount
{
    /** Receive the user repository and password hasher ports. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {}

    /**
     * Create an active account whose temporary password must be changed at the first login (UP-04, SE-07).
     * Linking an existing account is the invitation flow (RF-06), so a repeated email is refused.
     *
     * @throws EmailAlreadyRegisteredException
     */
    public function execute(RegisterStaffAccountCommand $command): User
    {
        $name = new UserName($command->name);
        $email = new Email($command->email);
        $password = new PlainPassword($command->temporaryPassword);

        $user = User::createStaff($this->users->nextId(), $name, $email, $this->passwordHasher->hash($password->value));

        $this->users->save($user);

        return $user;
    }
}
