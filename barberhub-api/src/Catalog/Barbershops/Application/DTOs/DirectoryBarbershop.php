<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class DirectoryBarbershop
{
    /**
     * Carry what the public directory shows of a barbershop: its name, city, logo and link, nothing else (RF-54, MT-09). The logo
     * is null while the barbershop has none.
     */
    public function __construct(
        public string $name,
        public string $city,
        public ?string $logo,
        public string $link,
    ) {}
}
