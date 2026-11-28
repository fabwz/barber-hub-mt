<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

use SensitiveParameter;
use Src\Catalog\Barbershops\Domain\Entities\Barbershop;

final readonly class RegisteredBarbershop
{
    /** The barbershop that was registered and what the operator needs to know about its owner's account. */
    public function __construct(
        public Barbershop $barbershop,
        public string $ownerEmail,
        public bool $ownerAccountCreated,
        #[SensitiveParameter] public ?string $temporaryPassword,
    ) {}
}
