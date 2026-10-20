<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Domain\Contracts\TokenRevoker;
use Src\Identity\Users\Domain\ValueObjects\UserId;

final readonly class RevokeCurrentToken
{
    /** Receive the token revoker port. */
    public function __construct(private TokenRevoker $tokens) {}

    /** Close the session of the device that owns the token. */
    public function execute(string $userId, string $tokenId): void
    {
        $this->tokens->revoke(new UserId($userId), $tokenId);
    }
}
