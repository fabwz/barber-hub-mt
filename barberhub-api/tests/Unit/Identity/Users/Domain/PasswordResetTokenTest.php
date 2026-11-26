<?php

declare(strict_types=1);

namespace Tests\Unit\Identity\Users\Domain;

use PHPUnit\Framework\TestCase;
use Src\Identity\Users\Domain\ValueObjects\PasswordResetToken;

final class PasswordResetTokenTest extends TestCase
{
    /** The token is 256 random bits in hexadecimal, and two tokens differ. */
    public function test_a_token_is_long_random_and_different_every_time(): void
    {
        $first = PasswordResetToken::generate();
        $second = PasswordResetToken::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first->plain);
        $this->assertNotSame($first->plain, $second->plain);
    }

    /** What is stored is the SHA-256 of the token, never the token (SE-06). */
    public function test_the_stored_form_is_the_sha256_hash_not_the_token(): void
    {
        $token = PasswordResetToken::generate();

        $this->assertSame(hash('sha256', $token->plain), $token->hash());
        $this->assertNotSame($token->plain, $token->hash());
        $this->assertSame($token->hash(), PasswordResetToken::fromPlain($token->plain)->hash());
    }

    /** The link lasts 60 minutes (SE-06). */
    public function test_the_link_lasts_sixty_minutes(): void
    {
        $this->assertSame(60, PasswordResetToken::TTL_MINUTES);
    }
}
