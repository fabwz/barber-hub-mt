<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\RegisterInvitedAccountCommand;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class RegisterInvitedAccount
{
    /** Receive the user repository and password hasher ports. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {}

    /**
     * Create the active account of a person who accepted an invitation, with the password they chose (RF-06, SE-07). The password is stored
     * hashed (SE-01) and is not temporary: nobody else ever knew it.
     *
     * @return string the identifier of the new account
     *
     * @throws InvalidValueException
     * @throws EmailAlreadyRegisteredException
     */
    public function execute(RegisterInvitedAccountCommand $command): string
    {
        $user = User::createInvited(
            $this->users->nextId(),
            new UserName($command->name),
            new Email($command->email),
            $this->passwordHasher->hash((new PlainPassword($command->password))->value),
        );

        $this->users->save($user);

        return $user->id->value;
    }
}
