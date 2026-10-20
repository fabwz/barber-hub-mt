<?php

declare(strict_types=1);

namespace Src\Identity\Users\Application\UseCases;

use Src\Identity\Users\Domain\Contracts\TokenRevoker;
use Src\Identity\Users\Domain\ValueObjects\UserId;

final readonly class RevokeAllTokens
{
    /** Receive the token revoker port. */
    public function __construct(private TokenRevoker $tokens) {}

    /** Close the sessions of the user on every device. */
    public function execute(string $userId): void
    {
        $this->tokens->revokeAll(new UserId($userId));
    }
}
