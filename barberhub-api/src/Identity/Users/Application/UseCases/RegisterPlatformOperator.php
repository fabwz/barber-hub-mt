<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\RegisterPlatformOperatorCommand;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Domain\ValueObjects\UserName;

final readonly class RegisterPlatformOperator
{
    /** Receive the user repository and password hasher ports. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {}

    /**
     * Create the account of a platform operator, marked as such and with a temporary password that must be changed at the first login
     * (RF-55, PL-01, RF-40). A repeated email is refused: an account that already exists is never turned into an operator, because the
     * operator holds no role in any barbershop.
     *
     * @throws EmailAlreadyRegisteredException
     */
    public function execute(RegisterPlatformOperatorCommand $command): User
    {
        $name = new UserName($command->name);
        $email = new Email($command->email);
        $password = new PlainPassword($command->temporaryPassword);

        $user = User::createPlatformOperator($this->users->nextId(), $name, $email, $this->passwordHasher->hash($password->value));

        $this->users->save($user);

        return $user;
    }
}
