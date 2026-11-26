<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Identity\Users\Domain\Contracts\PasswordResetLimiter;
use Src\Identity\Users\Domain\ValueObjects\Email;

final class FakePasswordResetLimiter implements PasswordResetLimiter
{
    /** Seconds the fake answers as the wait; 0 accepts the request. */
    public int $waitFor = 0;

    /** @var list<array{string, string}> */
    public private(set) array $hits = [];

    /** Remember the request and answer the configured wait. */
    public function hit(Email $email, string $ipAddress): int
    {
        $this->hits[] = [$email->value, $ipAddress];

        return $this->waitFor;
    }
}
