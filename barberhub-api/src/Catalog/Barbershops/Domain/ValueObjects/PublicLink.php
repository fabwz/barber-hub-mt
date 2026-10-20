<?php

declare(strict_types=1);

namespace Src\Catalog\Barbershops\Domain\ValueObjects;

use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class PublicLink
{
    public const int MAX_LENGTH = 120;

    /** Lowercase letters and digits in groups joined by single hyphens. */
    public const string PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    /** Reject anything but a lowercase, hyphenated, non-empty link within the maximum length (MT-07). */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidValueException('barbershops.public_link_invalid', ['max' => self::MAX_LENGTH]);
        }
    }
}
