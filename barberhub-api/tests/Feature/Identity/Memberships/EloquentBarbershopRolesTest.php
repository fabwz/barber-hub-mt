<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Memberships;

use Illuminate\Support\Str;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Memberships\Infrastructure\Persistence\Repositories\EloquentBarbershopRoles;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\Contracts\BarbershopRoles;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class EloquentBarbershopRolesTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    /** The port is bound to the adapter of the memberships module. */
    public function test_port_is_bound_to_the_adapter(): void
    {
        $this->assertInstanceOf(EloquentBarbershopRoles::class, $this->app->make(BarbershopRoles::class));
    }

    /** The role of an active membership is answered as text. */
    public function test_answers_the_role_of_an_active_membership(): void
    {
        [$user, $barbershop] = [$this->createUser('ana@barberhub.test'), $this->createBarbershop()];
        $this->createMembership($user, $barbershop, 'collaborator', true);

        $this->assertSame('collaborator', $this->roleOf($user, $barbershop));
    }

    /** A person with no membership in the barbershop has no role, even when they hold one in another. */
    public function test_answers_nothing_without_a_membership_in_that_barbershop(): void
    {
        $user = $this->createUser('ana@barberhub.test');
        $mine = $this->createBarbershop();
        $other = $this->createBarbershop();
        $this->createMembership($user, $mine, 'owner', true);

        $this->assertSame('owner', $this->roleOf($user, $mine));
        $this->assertNull($this->roleOf($user, $other));
    }

    /** A deactivated membership grants nothing. */
    public function test_answers_nothing_for_an_inactive_membership(): void
    {
        [$user, $barbershop] = [$this->createUser('ana@barberhub.test'), $this->createBarbershop()];
        $this->createMembership($user, $barbershop, 'owner', false);

        $this->assertNull($this->roleOf($user, $barbershop));
    }

    /** The role of one person is not given to another. */
    public function test_answers_nothing_for_another_person(): void
    {
        $barbershop = $this->createBarbershop();
        $this->createMembership($this->createUser('ana@barberhub.test'), $barbershop, 'owner', true);

        $this->assertNull($this->roleOf($this->createUser('luis@barberhub.test'), $barbershop));
    }

    /** Ask the adapter for the role of a person in a barbershop. */
    private function roleOf(string $userId, string $barbershopId): ?string
    {
        return (new EloquentBarbershopRoles)->roleOf(new UserId($userId), new BarbershopId($barbershopId));
    }

    /** Create an account and return its identifier. */
    private function createUser(string $email): string
    {
        return UserModel::query()->create(['name' => 'Ana Mora', 'email' => $email, 'password' => 'hash'])->id;
    }

    /** Create a barbershop and return its identifier. */
    private function createBarbershop(): string
    {
        return BarbershopModel::query()->create([
            'name' => 'Pura Navaja',
            'city' => 'San Carlos',
            'phone' => '2460-0000',
            'public_link' => (string) Str::uuid7(),
        ])->id;
    }

    /** Give a person a role in a barbershop. */
    private function createMembership(string $userId, string $barbershopId, string $role, bool $active): void
    {
        MembershipModel::query()->create([
            'user_id' => $userId,
            'barbershop_id' => $barbershopId,
            'role' => $role,
            'is_active' => $active,
        ]);
    }
}
