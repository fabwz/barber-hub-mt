<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

interface CollaboratorDirectory
{
    /** The identifier of the collaborator profile of an account in the current barbershop, or none if it has no agenda (CO-04). */
    public function idOfUser(string $userId): ?string;

    /**
     * The name of every collaborator of the current barbershop, active or not, indexed by collaborator. A barbershop has a
     * handful of them, so one read serves any page of reservations.
     *
     * @return array<string, string>
     */
    public function names(): array;
}
