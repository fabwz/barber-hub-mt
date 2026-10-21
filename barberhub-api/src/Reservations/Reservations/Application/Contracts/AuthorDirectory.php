<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface AuthorDirectory
{
    /**
     * The name of each account, indexed by account, in one read. An unknown account is skipped. Never the email (CO-04).
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function namesOf(array $userIds): array;
}
