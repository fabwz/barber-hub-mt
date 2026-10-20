<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\DTOs;

final readonly class CollaboratorMembershipCommand
{
    /** Carry the person and the barbershop of a collaborator role. */
    public function __construct(
        public string $barbershopId,
        public string $userId,
    ) {}
}
