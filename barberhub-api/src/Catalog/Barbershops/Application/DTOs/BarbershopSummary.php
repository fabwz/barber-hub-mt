<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class BarbershopSummary
{
    /** Carry what a person needs to recognise one of their barbershops. */
    public function __construct(
        public string $id,
        public string $name,
        public string $link,
    ) {}
}
