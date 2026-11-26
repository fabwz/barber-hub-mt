<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\ValueObjects;

use SensitiveParameter;

/**
 * The secret of the link to reset a password (RF-41, SE-06): 256 random bits that travel only in the email. What is stored is its SHA-256
 * hash, so a copy of the table cannot be turned into a link. The token is long and random, so a fast hash is enough: there is nothing to guess.
 */
final readonly class PasswordResetToken
{
    /** Minutes the link stays valid (SE-06). */
    public const int TTL_MINUTES = 60;

    /** Bytes of randomness of the token. */
    private const int BYTES = 32;

    private function __construct(public string $plain) {}

    /** Draw a new token from the random generator of the system. */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(self::BYTES)));
    }

    /** What the person sends back from the link; it is not checked here, the stored hash decides. */
    public static function fromPlain(#[SensitiveParameter] string $plain): self
    {
        return new self($plain);
    }

    /** The hash that is stored and compared; it never leaves the storage as a link. */
    public function hash(): string
    {
        return hash('sha256', $this->plain);
    }
}
