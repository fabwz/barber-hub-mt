<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\ValueObjects;

use SensitiveParameter;
use Src\Shared\Domain\Exceptions\InvalidValueException;

final readonly class PlainPassword
{
    public const int MIN_LENGTH = 8;

    /**
     * Translation key of each requirement of SE-07, by the pattern a password must match.
     * A special sign is a symbol or a punctuation mark (\p{S}, \p{P}); separators and spaces do not count.
     */
    private const array PATTERNS = [
        'identity.password_without_uppercase' => '/\p{Lu}/u',
        'identity.password_without_lowercase' => '/\p{Ll}/u',
        'identity.password_without_number' => '/\pN/u',
        'identity.password_without_symbol' => '/[\p{S}\p{P}]/u',
    ];

    public string $value;

    /** Reject a password that misses a requirement and keep the text exactly as typed. */
    public function __construct(#[SensitiveParameter] string $value)
    {
        $missing = self::missingRequirements($value);

        if ($missing !== []) {
            throw new InvalidValueException($missing[0], ['min' => self::MIN_LENGTH]);
        }

        $this->value = $value;
    }

    /**
     * Translation keys of the requirements the password does not meet, in a fixed order; empty when it is valid.
     *
     * @return list<string>
     */
    public static function missingRequirements(#[SensitiveParameter] string $value): array
    {
        $missing = [];

        if (mb_strlen($value) < self::MIN_LENGTH) {
            $missing[] = 'identity.password_too_short';
        }

        foreach (self::PATTERNS as $key => $pattern) {
            if (preg_match($pattern, $value) !== 1) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
