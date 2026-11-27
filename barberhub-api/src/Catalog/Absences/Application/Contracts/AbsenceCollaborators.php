<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Application\Contracts;

use Src\Shared\Domain\Exceptions\NotFoundException;

interface AbsenceCollaborators
{
    /**
     * Lock the collaborator of the current barbershop until the open transaction ends and tell whether they are active. It is the
     * same lock the reservations take (RE-01), so a booking and an absence for the same collaborator decide one at a time. The
     * caller must have a transaction open.
     *
     * @throws NotFoundException when the collaborator is not in the current barbershop
     */
    public function lockAndCheckActive(string $collaboratorId): bool;
}
