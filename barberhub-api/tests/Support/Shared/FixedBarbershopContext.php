<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use Src\Shared\Domain\Contracts\BarbershopContext;
use Src\Shared\Domain\ValueObjects\BarbershopId;

final readonly class FixedBarbershopContext implements BarbershopContext
{
    /** Keep the barbershop every operation of the test belongs to. */
    public function __construct(private BarbershopId $barbershopId) {}

    /** Answer the barbershop given at construction. */
    public function current(): BarbershopId
    {
        return $this->barbershopId;
    }
}
