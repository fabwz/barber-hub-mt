<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

/** The optional functions a barbershop turned on: the sale of vouchers, the waitlist (RF-09) and its place in the public directory (RF-54). All start off. */
final readonly class BarbershopFeatures
{
    /** Hold the switches. */
    private function __construct(public bool $vouchersEnabled, public bool $waitlistEnabled, public bool $listedInDirectory) {}

    /** Build the switches with the given values. */
    public static function of(bool $vouchersEnabled, bool $waitlistEnabled, bool $listedInDirectory = false): self
    {
        return new self($vouchersEnabled, $waitlistEnabled, $listedInDirectory);
    }

    /** What a new barbershop starts with: no vouchers, no waitlist and out of the directory. */
    public static function initial(): self
    {
        return new self(false, false, false);
    }
}
