<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\ProvidedOwnerAccount;
use Src\Identity\Users\Application\DTOs\ProvideOwnerAccountCommand;
use Src\Identity\Users\Domain\Contracts\PasswordHasher;
use Src\Identity\Users\Domain\Contracts\TemporaryPasswordGenerator;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\PlainPassword;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\ValueObjects\Phone;

final readonly class ProvideOwnerAccount
{
    /** Receive the user repository, the password hasher and the generator of temporary passwords. */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private TemporaryPasswordGenerator $passwords,
    ) {}

    /**
     * Give the owner of a new barbershop an account (RF-01, UP-05). An email with an account keeps it as it is, password included, and
     * the other data given is ignored. An email without one gets a new account with a generated temporary password that meets SE-07 and
     * must be changed at the first login (UP-04); the clear password is returned here and nowhere else: only its hash is stored.
     *
     * @throws EmailAlreadyRegisteredException when another request created the same email in between
     */
    public function execute(ProvideOwnerAccountCommand $command): ProvidedOwnerAccount
    {
        $email = new Email($command->email);
        $existing = $this->users->findByEmail($email);

        if ($existing !== null) {
            return new ProvidedOwnerAccount($existing->id->value, $existing->email->value, null);
        }

        $name = new UserName($command->name);
        $phone = new Phone($command->phone);
        $password = new PlainPassword($this->passwords->generate());

        $user = User::createOwner($this->users->nextId(), $name, $email, $phone, $this->passwordHasher->hash($password->value));
        $this->users->save($user);

        return new ProvidedOwnerAccount($user->id->value, $email->value, $password->value);
    }
}
