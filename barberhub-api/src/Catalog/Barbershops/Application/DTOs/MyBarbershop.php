<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class MyBarbershop
{
    /** Carry a barbershop of the person together with the role they hold in it and their collaborator profile there, if any. */
    public function __construct(
        public string $id,
        public string $name,
        public string $link,
        public string $role,
        public ?string $collaboratorId,
    ) {}
}
