<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Domain\Exceptions\CollaboratorNotFoundException;

interface CollaboratorLock
{
    /**
     * Lock the collaborator of the current barbershop until the open transaction ends, so the reservations of the same
     * collaborator are decided one after the other (RE-01).
     *
     * @throws CollaboratorNotFoundException when the collaborator is not in the current barbershop
     */
    public function lock(string $collaboratorId): void;
}
