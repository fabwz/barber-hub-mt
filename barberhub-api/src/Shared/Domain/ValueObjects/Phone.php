<?php

declare(strict_types=1);

namespace Src\Shared\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class Phone
{
    public const int MAX_LENGTH = 30;

    public string $value;

    /** Trim the phone number and reject empty or oversized values; no format is imposed. */
    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidValueException('shared.phone_invalid', ['max' => self::MAX_LENGTH]);
        }

        $this->value = $value;
    }
}
