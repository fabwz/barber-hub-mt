<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class MyBarbershop
{
    /** Carry a barbershop of the person together with the role they hold in it. */
    public function __construct(
        public string $id,
        public string $name,
        public string $link,
        public string $role,
    ) {}
}
