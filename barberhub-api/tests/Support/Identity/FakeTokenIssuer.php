<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Identity\Users\Domain\Contracts\TokenIssuer;
use Src\Shared\Domain\ValueObjects\UserId;

final class FakeTokenIssuer implements TokenIssuer
{
    /** @var list<array{string, string}> */
    public private(set) array $issued = [];

    /** Remember the token request and answer a predictable token. */
    public function issue(UserId $userId, string $deviceName): string
    {
        $this->issued[] = [$userId->value, $deviceName];

        return count($this->issued).'|token-de-prueba';
    }
}
