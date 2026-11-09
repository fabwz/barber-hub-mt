<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

use Src\Catalog\Barbershops\Domain\Exceptions\InvalidPublicProfileValueException;

/** The email of the business that customers may write to; it is never the email of the owner's account (RF-01). */
final readonly class ContactEmail
{
    public const int MAX_LENGTH = 150;

    public string $value;

    /** Trim the email and reject values that are not an email address or are oversized. */
    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidPublicProfileValueException::contactEmail(self::MAX_LENGTH);
        }

        $this->value = $value;
    }
}
