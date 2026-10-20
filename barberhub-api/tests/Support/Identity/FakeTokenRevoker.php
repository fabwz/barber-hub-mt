<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use Src\Identity\Users\Domain\Contracts\TokenRevoker;
use Src\Identity\Users\Domain\ValueObjects\UserId;

final class FakeTokenRevoker implements TokenRevoker
{
    /** @var list<array{string, string}> */
    public private(set) array $revoked = [];

    /** @var list<string> */
    public private(set) array $revokedAll = [];

    /** Remember the user and the token to revoke. */
    public function revoke(UserId $userId, string $tokenId): void
    {
        $this->revoked[] = [$userId->value, $tokenId];
    }

    /** Remember the user whose tokens are all revoked. */
    public function revokeAll(UserId $userId): void
    {
        $this->revokedAll[] = $userId->value;
    }
}
