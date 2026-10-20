<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class PublicBarbershop
{
    /** Carry what any visitor may know about a barbershop. */
    public function __construct(
        public string $id,
        public string $name,
        public string $city,
        public string $phone,
    ) {}
}
