<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Identity\Users\Domain\ValueObjects\UserId;

interface UserRepository
{
    /** Generate the identity for a new user. */
    public function nextId(): UserId;

    /**
     * Persist a new or modified user.
     *
     * @throws EmailAlreadyRegisteredException
     */
    public function save(User $user): void;

    /** Find the account registered with an email address. */
    public function findByEmail(Email $email): ?User;
}
