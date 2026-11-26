<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

interface CollaboratorNames
{
    /**
     * The name of every collaborator of the current barbershop, indexed by collaborator. A barbershop has a handful of them, so one
     * read names every offer of a list (no query per offer).
     *
     * @return array<string, string>
     */
    public function names(): array;
}
