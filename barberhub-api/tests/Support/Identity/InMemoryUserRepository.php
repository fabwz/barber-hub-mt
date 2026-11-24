<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Identity\Users\Domain\Exceptions\EmailAlreadyRegisteredException;
use Src\Identity\Users\Domain\ValueObjects\Email;
use Src\Shared\Domain\ValueObjects\UserId;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    private int $sequence = 0;

    /** @var list<string> */
    private array $remindersOff = [];

    /** Generate a predictable identity. */
    public function nextId(): UserId
    {
        return new UserId(sprintf('0192f0c4-0000-7000-8000-%012d', ++$this->sequence));
    }

    /** Store the user enforcing one account per email. */
    public function save(User $user): void
    {
        $existing = $this->findByEmail($user->email);

        if ($existing !== null && ! $existing->id->equals($user->id)) {
            throw EmailAlreadyRegisteredException::create();
        }

        $this->users[$user->id->value] = $user;
    }

    /** Find the account with the given identifier. */
    public function findById(UserId $id): ?User
    {
        return $this->users[$id->value] ?? null;
    }

    /** Find the account registered with an email address. */
    public function findByEmail(Email $email): ?User
    {
        return array_find($this->users, fn (User $user): bool => $user->email->value === $email->value);
    }

    /**
     * Find the accounts with the given identifiers.
     *
     * @param  list<UserId>  $ids
     * @return list<User>
     */
    public function findMany(array $ids): array
    {
        $wanted = array_map(fn (UserId $id): string => $id->value, $ids);

        return array_values(array_filter($this->users, fn (User $user): bool => in_array($user->id->value, $wanted, true)));
    }

    /** Whether the account wants the reminders; a test turns them off with `turnRemindersOff`. */
    public function remindersEnabled(UserId $id): bool
    {
        return ! in_array($id->value, $this->remindersOff, true);
    }

    /** Turn the reminders of an account off. */
    public function turnRemindersOff(UserId $id): void
    {
        $this->remindersOff[] = $id->value;
    }

    /** Count the stored accounts. */
    public function count(): int
    {
        return count($this->users);
    }
}
