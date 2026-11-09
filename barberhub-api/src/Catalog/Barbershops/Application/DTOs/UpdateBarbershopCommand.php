<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class UpdateBarbershopCommand
{
    /**
     * Carry the changes to the barbershop; a null name, city or phone keeps its current value. The profile holds only the data
     * that came, under "address", "contact_email" and the name of each network ("whatsapp", "instagram", "facebook", "tiktok"):
     * a null or blank text removes that datum and a key that is missing keeps it.
     *
     * @param  array<string, string|null>  $profile
     */
    public function __construct(
        public ?string $name,
        public ?string $city,
        public ?string $phone,
        public array $profile,
    ) {}
}
