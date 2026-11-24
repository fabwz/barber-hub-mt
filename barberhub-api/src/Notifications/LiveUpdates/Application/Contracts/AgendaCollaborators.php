<?php

declare(strict_types=1);

namespace Src\Notifications\LiveUpdates\Application\Contracts;

interface AgendaCollaborators
{
    /** Identifier of the collaborator profile of an account in a barbershop, or null when the account has no agenda there (CO-04). */
    public function profileOf(string $userId, string $barbershopId): ?string;
}
