<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/** What the provider charged, in its own currency and in the smallest unit of it (cents); kept only for traceability. */
final readonly class ProviderAmount
{
    private function __construct(public string $currency, public int $minorUnits) {}

    /** Build the amount from an ISO 4217 code and a positive number of minor units. */
    public static function of(string $currency, int $minorUnits): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidValueException('payments.currency_invalid');
        }

        if ($minorUnits <= 0) {
            throw new InvalidValueException('payments.provider_amount_invalid');
        }

        return new self($currency, $minorUnits);
    }
}
