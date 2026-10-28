<?php

declare(strict_types=1);

namespace Src\Payments\Payments\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

/**
 * What the provider charged, in its own currency and in the smallest unit of it (cents); kept only for traceability. When the
 * currency is not the colon, `exchangeRate` is the rate used, in colones per unit of that currency, as decimal text.
 */
final readonly class ProviderAmount
{
    private function __construct(public string $currency, public int $minorUnits, public ?string $exchangeRate = null) {}

    /** Build the amount from an ISO 4217 code, a positive number of minor units and, if it was converted, the rate used. */
    public static function of(string $currency, int $minorUnits, ?string $exchangeRate = null): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidValueException('payments.currency_invalid');
        }

        if ($minorUnits <= 0) {
            throw new InvalidValueException('payments.provider_amount_invalid');
        }

        if ($exchangeRate !== null && (preg_match('/^\d+(\.\d+)?$/', $exchangeRate) !== 1 || (float) $exchangeRate <= 0)) {
            throw new InvalidValueException('payments.exchange_rate_invalid');
        }

        return new self($currency, $minorUnits, $exchangeRate);
    }
}
