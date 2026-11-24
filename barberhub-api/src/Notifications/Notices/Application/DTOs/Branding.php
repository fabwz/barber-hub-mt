<?php

declare(strict_types=1);

namespace Src\Notifications\Notices\Application\DTOs;

/** The name of the barbershop and the absolute address of its logo, if it has one (MT-08). */
final readonly class Branding
{
    public function __construct(
        public string $barbershopName,
        public ?string $logoUrl,
    ) {}
}
