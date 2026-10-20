<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Identity\Users\Domain\Contracts\LoginAttempts;
use Src\Identity\Users\Domain\ValueObjects\Email;

final class FakeLoginAttempts implements LoginAttempts
{
    /** Seconds the fake answers as the remaining lock. */
    public int $lockedFor = 0;

    /** @var list<array{string, string}> */
    public private(set) array $failures = [];

    /** @var list<array{string, string}> */
    public private(set) array $resets = [];

    /** Answer the configured lock. */
    public function secondsUntilAllowed(Email $email, string $ipAddress): int
    {
        return $this->lockedFor;
    }

    /** Remember the failed attempt. */
    public function recordFailure(Email $email, string $ipAddress): void
    {
        $this->failures[] = [$email->value, $ipAddress];
    }

    /** Remember the reset. */
    public function reset(Email $email, string $ipAddress): void
    {
        $this->resets[] = [$email->value, $ipAddress];
    }
}
