<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Application\DTOs\AccountSummary;
use Src\Identity\Users\Domain\Contracts\UserRepository;
use Src\Identity\Users\Domain\Entities\User;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class ListAccountSummaries
{
    /** Receive the user repository port. */
    public function __construct(private UserRepository $users) {}

    /**
     * Describe the accounts with the given identifiers; an unknown identifier is skipped.
     *
     * @param  list<string>  $userIds
     * @return list<AccountSummary>
     */
    public function execute(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return array_map(
            fn (User $user): AccountSummary => new AccountSummary($user->id->value, $user->name->value, $user->email->value),
            $this->users->findMany(array_map(fn (string $id): UserId => new UserId($id), $userIds)),
        );
    }
}
