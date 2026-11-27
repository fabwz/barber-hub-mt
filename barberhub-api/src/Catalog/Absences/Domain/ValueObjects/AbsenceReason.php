<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class AbsenceReason
{
    public const int MAX_LENGTH = 200;

    public string $value;

    /** Trim the reason and reject empty or oversized values. */
    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidValueException('absences.reason_invalid', ['max' => self::MAX_LENGTH]);
        }

        $this->value = $value;
    }
}
