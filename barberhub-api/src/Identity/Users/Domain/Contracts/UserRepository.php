<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Shared\Domain\ValueObjects\UserId;

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

    /** Find the account with the given identifier. */
    public function findById(UserId $id): ?User;

    /** Find the account registered with an email address. */
    public function findByEmail(Email $email): ?User;

    /**
     * Find the accounts with the given identifiers; an unknown identifier is skipped.
     *
     * @param  list<UserId>  $ids
     * @return list<User>
     */
    public function findMany(array $ids): array;

    /** Whether the account wants the reminders of its reservations by email; it does until it says otherwise, and an unknown account does too (RF-47, NO-10). */
    public function remindersEnabled(UserId $id): bool;
}
