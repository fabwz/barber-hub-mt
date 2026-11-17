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

    /**
     * All the active collaborators of the current barbershop, whatever they offer: it tells a barber who does not exist from one who
     * exists but does not offer the service asked for.
     *
     * @return list<ProposableCollaborator>
     */
    public function active(): array;
}
