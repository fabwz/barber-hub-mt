<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Security;

use Illuminate\Cache\RateLimiter;
use Src\Identity\Users\Domain\Contracts\LoginAttempts;
use Src\Identity\Users\Domain\ValueObjects\Email;

final readonly class RateLimiterLoginAttempts implements LoginAttempts
{
    /** Receive the Laravel rate limiter, which stores its counters in the cache. */
    public function __construct(private RateLimiter $limiter) {}

    /** Seconds left of the lock of this email and address; 0 when there is none. */
    public function secondsUntilAllowed(Email $email, string $ipAddress): int
    {
        $lock = $this->lockKey($email, $ipAddress);

        return $this->limiter->tooManyAttempts($lock, 1) ? $this->limiter->availableIn($lock) : 0;
    }

    /**
     * Count a failure; the fifth one clears the counter and starts a lock of its own.
     *
     * The limiter measures its window from the first hit, so the counter alone would
     * unlock 15 minutes after the first failure. The lock key is hit once, at the
     * fifth failure, which makes the 15 minutes run from that moment (SE-05).
     */
    public function recordFailure(Email $email, string $ipAddress): void
    {
        $failures = $this->failuresKey($email, $ipAddress);

        if ($this->limiter->hit($failures, self::LOCKOUT_SECONDS) >= self::MAX_FAILURES) {
            $this->limiter->clear($failures);
            $this->limiter->hit($this->lockKey($email, $ipAddress), self::LOCKOUT_SECONDS);
        }
    }

    /** Forget the failures after a successful login. */
    public function reset(Email $email, string $ipAddress): void
    {
        $this->limiter->clear($this->failuresKey($email, $ipAddress));
    }

    /** Key of the failure counter. */
    private function failuresKey(Email $email, string $ipAddress): string
    {
        return $this->key($email, $ipAddress).':failures';
    }

    /** Key of the lock. */
    private function lockKey(Email $email, string $ipAddress): string
    {
        return $this->key($email, $ipAddress).':lock';
    }

    /** Hash the normalised email and the address, so the cache keeps no email. */
    private function key(Email $email, string $ipAddress): string
    {
        return 'login:'.hash('sha256', $email->value.'|'.$ipAddress);
    }
}
