<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\RegisterCustomerCommand;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class RegisterCustomer
{
    /** Receive the user repository and password hasher ports. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {}

    /**
     * Register an active account; it has no membership until the first use in a barbershop (UP-09).
     *
     * @throws EmailAlreadyRegisteredException
     */
    public function execute(RegisterCustomerCommand $command): User
    {
        $name = new UserName($command->name);
        $email = new Email($command->email);
        $phone = new Phone($command->phone);
        $password = new PlainPassword($command->password);

        $user = User::create(
            $this->users->nextId(),
            $name,
            $email,
            $phone,
            $this->passwordHasher->hash($password->value),
            $command->termsAcceptedAt,
            $command->privacyAcceptedAt,
        );

        $this->users->save($user);

        return $user;
    }
}
