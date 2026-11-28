<?php

declare(strict_types=1);

namespace Src\Identity\Users\Infrastructure\Persistence\Repositories;

use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\PlatformOperators;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class EloquentPlatformOperators implements PlatformOperators
{
    /** Read the mark of the account; an unknown account is not an operator. */
    public function isOperator(UserId $userId): bool
    {
        return UserModel::query()->whereKey($userId->value)->value('is_platform_operator') === true;
    }
}
