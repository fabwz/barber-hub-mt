<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface ActiveCollaboratorNames
{
    /**
     * The names of the active collaborators of the current barbershop, never their emails or anything of their accounts: they are what the language provider is told (IA-06).
     *
     * @return list<string>
     */
    public function all(): array;
}
