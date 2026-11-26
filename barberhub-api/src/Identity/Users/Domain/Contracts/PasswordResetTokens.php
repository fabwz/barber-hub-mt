<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

use DateTimeImmutable;
use Src\Shared\Domain\ValueObjects\UserId;

interface PasswordResetTokens
{
    /**
     * Keep the hash of a new link for the account and forget the one it had, so only the last link sent works (SE-06).
     * The token is never stored in plain text.
     */
    public function replace(UserId $userId, string $tokenHash, DateTimeImmutable $expiresAt): void;

    /**
     * Use the link once: answer the account it belongs to and delete it in the same step, so two requests with the same
     * link cannot both succeed; null when the hash is unknown, was used or has expired at `$now`. The hashes are compared in constant time.
     */
    public function consume(string $tokenHash, DateTimeImmutable $now): ?UserId;
}
