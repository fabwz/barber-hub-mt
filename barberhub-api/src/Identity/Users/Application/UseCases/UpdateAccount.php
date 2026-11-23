<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\UpdateAccountCommand;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\AccountNotFoundException;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserName;
use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Phone;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class UpdateAccount
{
    /** Receive the user repository port. */
    public function __construct(private UserRepository $users) {}

    /**
     * Correct the name, phone and email of the person's own account (RF-45, CL-03). An email that another account already
     * uses is refused; the person's own email is not a conflict. The password and the temporary state are not touched.
     *
     * @throws AccountNotFoundException
     * @throws EmailAlreadyRegisteredException
     * @throws InvalidValueException
     */
    public function execute(UpdateAccountCommand $command): User
    {
        $user = $this->users->findById(new UserId($command->userId)) ?? throw AccountNotFoundException::create();

        $updated = $user->updateProfile(new UserName($command->name), new Email($command->email), new Phone($command->phone));

        $this->users->save($updated);

        return $updated;
    }
}
