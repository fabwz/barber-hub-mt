<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Security;

use Illuminate\Cache\RateLimiter;
use Src\Identity\Users\Domain\Contracts\PasswordResetLimiter;
use Src\Identity\Users\Domain\ValueObjects\Email;

final readonly class RateLimiterPasswordResetLimiter implements PasswordResetLimiter
{
    /** Receive the Laravel rate limiter, which stores its counters in the cache. */
    public function __construct(private RateLimiter $limiter) {}

    /** Count the request against the email and against the address; refuse it, uncounted, when either has already made the 5 of the hour. */
    public function hit(Email $email, string $ipAddress): int
    {
        $keys = [$this->key('email', $email->value), $this->key('ip', $ipAddress)];

        $wait = 0;
        foreach ($keys as $key) {
            if ($this->limiter->tooManyAttempts($key, self::MAX_REQUESTS)) {
                $wait = max($wait, $this->limiter->availableIn($key));
            }
        }

        if ($wait > 0) {
            return $wait;
        }

        foreach ($keys as $key) {
            $this->limiter->hit($key, self::WINDOW_SECONDS);
        }

        return 0;
    }

    /** Hash the email or the address, so the cache keeps neither. */
    private function key(string $kind, string $value): string
    {
        return "password-reset:{$kind}:".hash('sha256', $value);
    }
}
