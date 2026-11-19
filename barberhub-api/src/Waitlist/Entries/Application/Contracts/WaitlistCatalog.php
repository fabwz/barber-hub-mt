<?php

declare(strict_types=1);

namespace Src\Waitlist\Entries\Application\Contracts;

interface WaitlistCatalog
{
    /** Whether the service is an active one of the current barbershop (SV-04). */
    public function hasService(string $serviceId): bool;

    /** Whether the collaborator is one of the current barbershop. */
    public function hasCollaborator(string $collaboratorId): bool;
}
