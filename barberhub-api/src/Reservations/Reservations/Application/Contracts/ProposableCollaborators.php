<?php

declare(strict_types=1);

namespace Src\Reservations\Reservations\Application\Contracts;

use Src\Reservations\Reservations\Application\DTOs\ProposableCollaborator;

interface ProposableCollaborators
{
    /**
     * The active collaborators of the current barbershop who offer a service, with their names and never their accounts (CO-01).
     *
     * @return list<ProposableCollaborator>
     */
    public function offering(string $serviceId): array;
}
