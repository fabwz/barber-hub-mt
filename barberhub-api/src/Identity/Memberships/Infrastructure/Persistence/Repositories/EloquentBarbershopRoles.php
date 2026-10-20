<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Infrastructure\Persistence\Repositories;

use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class EloquentBarbershopRoles implements BarbershopRoles
{
    /** Read the role of the person in the barbershop from their active membership. */
    public function roleOf(UserId $userId, BarbershopId $barbershopId): ?string
    {
        $role = MembershipModel::query()
            ->where('user_id', $userId->value)
            ->where('barbershop_id', $barbershopId->value)
            ->where('is_active', true)
            ->value('role');

        return is_string($role) ? $role : null;
    }
}
