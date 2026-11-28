<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class RegisterBarbershopCommand
{
    /** Carry the data of the barbershop, of the person who becomes its owner and of the platform operator who registers it. */
    public function __construct(
        public string $name,
        public string $city,
        public string $phone,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerPhone,
        public string $operatorId,
    ) {}
}
