<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Security;

use Src\Identity\Users\Domain\Contracts\TokenRevoker;
use Src\Identity\Users\Domain\ValueObjects\UserId;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;

final readonly class SanctumTokenRevoker implements TokenRevoker
{
    /** Delete one Sanctum token, looked up among the tokens of the user. */
    public function revoke(UserId $userId, string $tokenId): void
    {
        $this->userOf($userId)->tokens()->whereKey($tokenId)->delete();
    }

    /** Delete every Sanctum token of the user. */
    public function revokeAll(UserId $userId): void
    {
        $this->userOf($userId)->tokens()->delete();
    }

    /** Load the model that owns the tokens. */
    private function userOf(UserId $userId): UserModel
    {
        return UserModel::query()->findOrFail($userId->value);
    }
}
