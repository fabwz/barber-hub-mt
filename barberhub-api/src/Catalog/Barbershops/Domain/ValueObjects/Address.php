<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

use Src\Catalog\Barbershops\Domain\Exceptions\InvalidPublicProfileValueException;

/** The street address of a barbershop, as free text on one line (RF-01). */
final readonly class Address
{
    public const int MAX_LENGTH = 200;

    public string $value;

    /** Trim the address and reject empty, oversized or multi-line values. */
    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $value) !== 0) {
            throw InvalidPublicProfileValueException::address(self::MAX_LENGTH);
        }

        $this->value = $value;
    }
}
