<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\DTOs;

final readonly class UserMembership
{
    /** Carry the active role of a person in one barbershop. */
    public function __construct(
        public string $barbershopId,
        public string $role,
    ) {}
}
