<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Application\DTOs;

final readonly class UpdateBarbershopFeaturesCommand
{
    /** Carry the changes to the switches; a null field keeps its current value. */
    public function __construct(public ?bool $vouchersEnabled, public ?bool $waitlistEnabled, public ?bool $listedInDirectory = null) {}
}
