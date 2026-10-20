<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Security;

use Src\Identity\Users\Domain\Contracts\TokenIssuer;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class SanctumTokenIssuer implements TokenIssuer
{
    /** Create a Sanctum personal access token named after the device, with no role abilities (the role is per barbershop). */
    public function issue(UserId $userId, string $deviceName): string
    {
        return UserModel::query()
            ->findOrFail($userId->value)
            ->createToken($deviceName)
            ->plainTextToken;
    }
}
