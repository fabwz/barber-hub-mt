<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Infrastructure\Accounts;

use Src\Identity\Users\Application\UseCases\ListAccountSummaries;
use Src\Reservations\Reservations\Application\Contracts\AuthorDirectory;

final readonly class UsersAuthorDirectory implements AuthorDirectory
{
    /** Receive the Users use case that describes accounts. */
    public function __construct(private ListAccountSummaries $listAccountSummaries) {}

    /**
     * Read the accounts in one query through the Users module and keep only the name.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function namesOf(array $userIds): array
    {
        $names = [];

        foreach ($this->listAccountSummaries->execute(array_values(array_unique($userIds))) as $summary) {
            $names[$summary->userId] = $summary->name;
        }

        return $names;
    }
}
