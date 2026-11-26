<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\Contracts;

interface CollaboratorNames
{
    /**
     * The name of every collaborator of the current barbershop, indexed by collaborator. A barbershop has a handful of them, so one
     * read names every row of a list (no query per row).
     *
     * @return array<string, string>
     */
    public function names(): array;
}
