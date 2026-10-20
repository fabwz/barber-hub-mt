<?php

declare(strict_types=1);

namespace Src\Catalog\Collaborators\Application\Contracts;

use SensitiveParameter;
use Src\Catalog\Collaborators\Application\DTOs\AccountDescription;

interface StaffAccounts
{
    /** Create the account of a member of the staff with a temporary password and return its identifier (UP-04, SE-07). */
    public function create(string $name, string $email, #[SensitiveParameter] string $temporaryPassword): string;

    /**
     * Describe the accounts with the given identifiers, indexed by identifier.
     *
     * @param  list<string>  $userIds
     * @return array<string, AccountDescription>
     */
    public function describe(array $userIds): array;
}
