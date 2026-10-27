<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Application\DTOs;

use Src\Shared\Domain\ValueObjects\Percentage;

/** How a barbershop charges: not at all, the percentage of the price as a deposit, or all of it (PA-01). */
final readonly class ChargePolicy
{
    private function __construct(public bool $charges, public ?Percentage $deposit) {}

    /** The barbershop charges nothing up front. */
    public static function none(): self
    {
        return new self(false, null);
    }

    /** The barbershop charges a percentage of the price. */
    public static function deposit(Percentage $percentage): self
    {
        return new self(true, $percentage);
    }

    /** The barbershop charges the whole price. */
    public static function full(): self
    {
        return new self(true, null);
    }
}
