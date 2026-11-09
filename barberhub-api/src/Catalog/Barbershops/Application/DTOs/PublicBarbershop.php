<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class PublicBarbershop
{
    /**
     * Carry what any visitor may know about a barbershop. The address and the contact email are null when not set, and the
     * social links hold only the networks that have one, by network name.
     *
     * @param  array<string, string>  $socialLinks
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $city,
        public string $phone,
        public ?string $address = null,
        public ?string $contactEmail = null,
        public array $socialLinks = [],
    ) {}
}
