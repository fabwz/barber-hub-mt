<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

use Src\Identity\Users\Domain\ValueObjects\Email;

interface LoginAttempts
{
    /** Consecutive failed attempts that lock new ones (SE-05). */
    public const int MAX_FAILURES = 5;

    /** Seconds the lock lasts, counted from the last allowed failure (SE-05). */
    public const int LOCKOUT_SECONDS = 900;

    /** Seconds left before the email may try again from that address; 0 when it may try now. */
    public function secondsUntilAllowed(Email $email, string $ipAddress): int;

    /** Count a failed attempt; the fifth one in a row starts the lock. */
    public function recordFailure(Email $email, string $ipAddress): void;

    /** Forget the failed attempts after a successful login. */
    public function reset(Email $email, string $ipAddress): void;
}
