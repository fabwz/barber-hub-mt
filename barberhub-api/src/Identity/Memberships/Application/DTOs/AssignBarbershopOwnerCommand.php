<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Application\DTOs;

final readonly class AssignBarbershopOwnerCommand
{
    /** Carry the person and the barbershop of the owner role to assign. */
    public function __construct(
        public string $barbershopId,
        public string $userId,
    ) {}
}
