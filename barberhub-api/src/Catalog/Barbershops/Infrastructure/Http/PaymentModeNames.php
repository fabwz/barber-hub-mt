<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Infrastructure\Http;

use Src\Catalog\Barbershops\Domain\ValueObjects\PaymentMode;

final class PaymentModeNames
{
    /**
     * The name of each mode in the API, in Spanish, as the glossary lists them.
     *
     * @var array<string, PaymentMode>
     */
    private const array BY_NAME = [
        'sin_pago' => PaymentMode::None,
        'deposito' => PaymentMode::Deposit,
        'total' => PaymentMode::Full,
    ];

    /** The name of the mode in the API. */
    public static function of(PaymentMode $mode): string
    {
        return (string) array_search($mode, self::BY_NAME, true);
    }

    /** The mode an API name stands for; the name must be one of `names()`. */
    public static function mode(string $name): PaymentMode
    {
        return self::BY_NAME[$name];
    }

    /**
     * Every name of the API.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::BY_NAME);
    }
}
