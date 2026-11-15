<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** The optional functions a barbershop turned on: the sale of vouchers and the waitlist (RF-09). Both start off. */
final readonly class BarbershopFeatures
{
    /** Hold the two switches. */
    private function __construct(public bool $vouchersEnabled, public bool $waitlistEnabled) {}

    /** Build the switches with the given values. */
    public static function of(bool $vouchersEnabled, bool $waitlistEnabled): self
    {
        return new self($vouchersEnabled, $waitlistEnabled);
    }

    /** What a new barbershop starts with: no vouchers and no waitlist. */
    public static function initial(): self
    {
        return new self(false, false);
    }
}
