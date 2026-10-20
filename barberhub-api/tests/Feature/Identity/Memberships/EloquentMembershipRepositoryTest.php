<?php

declare(strict_types=1);

namespace Tests\Feature\Identity\Memberships;

use Illuminate\Support\Str;
use Src\Catalog\Barbershops\Infrastructure\Persistence\Models\BarbershopModel;
use Src\Identity\Memberships\Domain\Entities\Membership;
use Src\Identity\Memberships\Domain\Exceptions\RoleAlreadyAssignedException;
use Src\Identity\Memberships\Domain\ValueObjects\Role;
use Src\Identity\Memberships\Infrastructure\Persistence\Models\MembershipModel;
use Src\Identity\Memberships\Infrastructure\Persistence\Repositories\EloquentMembershipRepository;
use Src\Identity\Users\Infrastructure\Persistence\Models\UserModel;
use Src\Shared\Domain\ValueObjects\BarbershopId;
use Src\Shared\Domain\ValueObjects\UserId;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

final class EloquentMembershipRepositoryTest extends TestCase
{
    use RefreshDatabaseAsOwner;

    private EloquentMembershipRepository $repository;

    private UserId $userId;

    private BarbershopId $barbershopId;

    /** Create the account, the barbershop and the repository every scenario works on. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EloquentMembershipRepository;
        $this->userId = new UserId($this->createUser('ana@barberhub.test'));
        $this->barbershopId = new BarbershopId($this->createBarbershop());
    }

    /** A saved membership keeps its role and is active. */
    public function test_membership_is_saved(): void
    {
        $membership = Membership::create($this->repository->nextId(), $this->userId, $this->barbershopId, Role::Owner);

        $this->repository->save($membership);

        $model = MembershipModel::query()->findOrFail($membership->id->value);
        $this->assertSame($this->userId->value, $model->user_id);
        $this->assertSame($this->barbershopId->value, $model->barbershop_id);
        $this->assertSame('owner', $model->role);
        $this->assertTrue($model->is_active);
    }

    /** Saving a membership again updates its row instead of adding another. */
    public function test_saving_the_same_membership_again_updates_it(): void
    {
        $id = $this->repository->nextId();
        $this->repository->save(Membership::create($id, $this->userId, $this->barbershopId, Role::Owner));

        $this->repository->save(Membership::reconstitute($id, $this->userId, $this->barbershopId, Role::Owner, false));

        $this->assertDatabaseCount('memberships', 1);
        $this->assertFalse(MembershipModel::query()->findOrFail($id->value)->is_active);
    }

    /** A saved membership is found by person and barbershop, and an unknown pair is not. */
    public function test_membership_is_found_by_user_and_barbershop(): void
    {
        $membership = Membership::create($this->repository->nextId(), $this->userId, $this->barbershopId, Role::Owner);
        $this->repository->save($membership);

        $this->assertEquals($membership, $this->repository->findByUserAndBarbershop($this->userId, $this->barbershopId));
        $this->assertNull($this->repository->findByUserAndBarbershop($this->userId, new BarbershopId($this->createBarbershop())));
    }

    /** A second role for the same person in the same barbershop is refused and the first one is kept (UP-07). */
    public function test_second_role_in_the_same_barbershop_is_refused(): void
    {
        $this->repository->save(Membership::create($this->repository->nextId(), $this->userId, $this->barbershopId, Role::Owner));

        try {
            $this->repository->save(Membership::create($this->repository->nextId(), $this->userId, $this->barbershopId, Role::Customer));
            $this->fail('A second role was accepted.');
        } catch (RoleAlreadyAssignedException $exception) {
            $this->assertSame('identity.role_already_assigned', $exception->translationKey());
            $this->assertSame('owner', MembershipModel::query()->sole()->role);
        }
    }

    /** Every new identity is a distinct time-ordered UUID. */
    public function test_next_id_returns_distinct_uuid_v7_values(): void
    {
        $first = $this->repository->nextId()->value;
        $second = $this->repository->nextId()->value;

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
        $this->assertNotSame($first, $second);
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
}
