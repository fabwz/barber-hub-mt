<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\DTOs;

final readonly class ProvideOwnerAccountCommand
{
    /** Carry the data of the person who becomes the owner of a new barbershop. */
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
    ) {}
}
