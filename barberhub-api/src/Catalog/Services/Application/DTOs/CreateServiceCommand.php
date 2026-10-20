<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\DTOs;

final readonly class CreateServiceCommand
{
    /** Carry the data needed to register a service; the price includes VAT. */
    public function __construct(
        public string $name,
        public int $durationMinutes,
        public int $price,
    ) {}
}
