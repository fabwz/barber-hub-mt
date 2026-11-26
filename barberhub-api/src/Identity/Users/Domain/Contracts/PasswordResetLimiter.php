<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

use Src\Identity\Users\Domain\ValueObjects\Email;

interface PasswordResetLimiter
{
    /** Requests a single email, and a single address, may make in the window (SE-06). */
    public const int MAX_REQUESTS = 5;

    /** Seconds of the window: one hour (SE-06). */
    public const int WINDOW_SECONDS = 3600;

    /**
     * Count a request against the email and against the address. Answers 0 when it is accepted, or the seconds to wait when either of
     * the two already made the 5 of the hour; a refused request is not counted. The count does not depend on the account existing.
     */
    public function hit(Email $email, string $ipAddress): int;
}
