<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\DTOs;

final readonly class UpdateServiceCommand
{
    /** Carry the fields to change; a null field keeps its current value. */
    public function __construct(
        public string $serviceId,
        public ?string $name = null,
        public ?int $durationMinutes = null,
        public ?int $price = null,
        public ?bool $active = null,
    ) {}
}
