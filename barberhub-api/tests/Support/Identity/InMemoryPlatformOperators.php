<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Shared\Domain\Contracts\PlatformOperators;
use Src\Shared\Domain\ValueObjects\UserId;

final class InMemoryPlatformOperators implements PlatformOperators
{
    /** @var array<string, true> */
    private array $operators = [];

    /** Mark the account as a platform operator. */
    public function mark(string $userId): void
    {
        $this->operators[$userId] = true;
    }

    /** Whether the account was marked. */
    public function isOperator(UserId $userId): bool
    {
        return isset($this->operators[$userId->value]);
    }
}
