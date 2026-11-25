<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\Contracts;

interface CollaboratorProfiles
{
    /** Give the identifier of the collaborator profile of the person in a barbershop where they already hold a role, or none if they have no agenda there (CO-02, CO-04). */
    public function of(string $userId, string $barbershopId): ?string;
}
