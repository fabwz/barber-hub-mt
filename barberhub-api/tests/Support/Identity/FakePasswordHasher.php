<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Identity\Users\Domain\Contracts\PasswordHasher;

final class FakePasswordHasher implements PasswordHasher
{
    private const string PREFIX = 'hashed:';

    public private(set) int $checks = 0;

    /** Mark the password as hashed without the cost of a real algorithm. */
    public function hash(string $plainPassword): string
    {
        return self::PREFIX.$plainPassword;
    }

    /** Count the comparison and compare the password with its marked form. */
    public function check(string $plainPassword, ?string $hash): bool
    {
        $this->checks++;

        return $hash === self::PREFIX.$plainPassword;
    }
}
