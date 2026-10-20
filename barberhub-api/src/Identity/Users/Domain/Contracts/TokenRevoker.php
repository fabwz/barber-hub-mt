<?php

declare(strict_types=1);

namespace Src\Identity\Users\Domain\Contracts;

use Src\Identity\Users\Domain\ValueObjects\UserId;

interface TokenRevoker
{
    /** Revoke one access token of the user; a token of someone else is left untouched. */
    public function revoke(UserId $userId, string $tokenId): void;

    /** Revoke every access token of the user. */
    public function revokeAll(UserId $userId): void;
}
