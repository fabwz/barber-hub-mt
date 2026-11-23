<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Application\DTOs;

final readonly class PublicService
{
    /** Carry what a customer sees of a service: the price with VAT included, split into base and tax (RL-02), and the address of its image, null when it has none (SV-07). */
    public function __construct(
        public string $id,
        public string $name,
        public int $durationMinutes,
        public int $price,
        public int $base,
        public int $tax,
        public ?string $image = null,
    ) {}
}
