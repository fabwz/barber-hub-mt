<?php

declare(strict_types=1);

namespace Src\Waitlist\Offers\Application\Contracts;

interface CollaboratorLock
{
    /**
     * Lock the collaborator of the current barbershop until the open transaction ends: the same lock the reservations take, so a space is
     * offered and booked one after the other and never both (RE-01, DI-05). It tells whether the collaborator is of the barbershop.
     */
    public function lock(string $collaboratorId): bool;
}
