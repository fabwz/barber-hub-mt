<?php

declare(strict_types=1);

namespace Src\Catalog\Services\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;
use Src\Shared\Domain\ValueObjects\Money;

final readonly class ServicePrice
{
    /** VAT rate, in percent, that the price already includes (SV-02, RL-02). */
    public const int TAX_RATE = 13;

    public Money $money;

    /** Accept whole colones greater than zero, VAT included (SV-01). */
    public function __construct(int $colones)
    {
        if ($colones <= 0) {
            throw new InvalidValueException('services.price_invalid');
        }

        $this->money = Money::of($colones);
    }

    /** The price with VAT included, in colones. */
    public function colones(): int
    {
        return $this->money->colones;
    }

    /**
     * The VAT inside the price: price × 13 / 113, rounded to the nearest colon with halves going up (SV-02).
     * Integer arithmetic avoids floating-point error: floor((2 × price × 13 + 113) / (2 × 113)).
     */
    public function tax(): Money
    {
        $divisor = 100 + self::TAX_RATE;

        return Money::of(intdiv(2 * $this->money->colones * self::TAX_RATE + $divisor, 2 * $divisor));
    }

    /** The price without VAT: what is left after the tax, so that base plus tax is exactly the price (SV-02). */
    public function base(): Money
    {
        return $this->money->minus($this->tax());
    }
}
