<?php

declare(strict_types=1);

namespace Src\Identity\Memberships\Infrastructure\Persistence\Repositories;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Src\Identity\Memberships\Domain\Contracts\MembershipRepository;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\MembershipId;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;

final readonly class EloquentMembershipRepository implements MembershipRepository
{
    /** Generate a time-ordered UUID for a new membership. */
    public function nextId(): MembershipId
    {
        return new MembershipId((string) Str::uuid7());
    }

    /** Insert or update the membership inside a savepoint and translate a second role for the same person (UP-07). */
    public function save(Membership $membership): void
    {
        try {
            DB::transaction(fn () => MembershipModel::query()->updateOrCreate(
                ['id' => $membership->id->value],
                [
                    'user_id' => $membership->userId->value,
                    'barbershop_id' => $membership->barbershopId->value,
                    'role' => $membership->role->value,
                    'is_active' => $membership->active,
                ],
            ));
        } catch (UniqueConstraintViolationException) {
            throw RoleAlreadyAssignedException::create();
        }
    }

    /** Find the role of the person in the barbershop. */
    public function findByUserAndBarbershop(UserId $userId, BarbershopId $barbershopId): ?Membership
    {
        $model = MembershipModel::query()
            ->where('user_id', $userId->value)
            ->where('barbershop_id', $barbershopId->value)
            ->first();

        return $model === null ? null : Membership::reconstitute(
            new MembershipId($model->id),
            new UserId($model->user_id),
            new BarbershopId($model->barbershop_id),
            Role::from($model->role),
            $model->is_active,
        );
    }
}
