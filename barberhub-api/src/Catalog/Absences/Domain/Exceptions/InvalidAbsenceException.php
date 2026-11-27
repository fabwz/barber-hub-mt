<?php

declare(strict_types=1);

namespace Src\Catalog\Absences\Domain\Exceptions;

use Src\Shared\Domain\Exceptions\HasInvalidField;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final class InvalidAbsenceException extends InvalidValueException implements HasInvalidField
{
    private string $field = 'fecha_hasta';

    /** A date that is not a real calendar date written as AAAA-MM-DD. */
    public static function dateInvalid(string $field): self
    {
        return self::about('absences.date_invalid', $field);
    }

    /** An hour that is not a 24-hour time written as HH:MM. */
    public static function timeInvalid(string $field): self
    {
        return self::about('absences.time_invalid', $field);
    }

    /** Only one of the two hours came: they come together or not at all. */
    public static function hoursTogether(string $missing): self
    {
        return self::about('absences.hours_together', $missing);
    }

    /** The range ends before it starts, or at the very instant it starts. */
    public static function rangeInverted(string $field): self
    {
        return self::about('absences.range_inverted', $field);
    }

    /** The range is already over: blocking a time that passed means nothing. */
    public static function alreadyEnded(): self
    {
        return self::about('absences.already_ended', 'fecha_hasta');
    }

    /** The attribute of the request that carries the wrong value. */
    public function field(): string
    {
        return $this->field;
    }

    /** Build the exception for a translation key and the attribute it points to. */
    private static function about(string $key, string $field): self
    {
        $exception = new self($key);
        $exception->field = $field;

        return $exception;
    }
}
