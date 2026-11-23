<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\AccountNotFoundException;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ShowAccount
{
    /** Receive the user repository port. */
    public function __construct(private UserRepository $users) {}

    /**
     * Read the account of the person who asks (RF-45).
     *
     * @throws AccountNotFoundException
     */
    public function execute(string $userId): User
    {
        return $this->users->findById(new UserId($userId)) ?? throw AccountNotFoundException::create();
    }
}
