<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class BarbershopRole
{
    /** Carry the role a person holds in a barbershop. */
    public function __construct(
        public string $barbershopId,
        public string $role,
    ) {}
}
