<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class RegisterBarbershopCommand
{
    /** Carry the data needed to register a barbershop for the person who becomes its owner. */
    public function __construct(
        public string $name,
        public string $city,
        public string $phone,
        public string $ownerId,
    ) {}
}
