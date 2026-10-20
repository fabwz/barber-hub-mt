<?php

declare(strict_types=1);

namespace Src\Shared\Domain\Contracts;

use Src\Shared\Domain\ValueObjects\BarbershopId;

interface BarbershopContext
{
    /** Identify the barbershop the current operation belongs to. */
    public function current(): BarbershopId;
}
